package main

import (
	"encoding/csv"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

type SiteInfo struct {
	Multisite int
	Siteurl   string
}

type Event struct {
	url       string
	timestamp string
	action    string
	instance  string
}

var (
	wpCliPath string
	wpPath    string

	workersGetEvents int
	workersRunEvents int

	logger  *log.Logger
	logDest string
)

const getEventsLoop time.Duration  = time.Minute
const getEventsBreak time.Duration = time.Second
const runEventsBreak time.Duration = time.Second * 10

func init() {
	flag.StringVar(&wpCliPath, "cli", "/usr/local/bin/wp", "Path to WP-CLI binary")
	flag.StringVar(&wpPath, "wp", "/var/www/html", "Path to WordPress installation")
	flag.IntVar(&workersGetEvents, "workers-get", 3, "Number of workers to retrieve events")
	flag.IntVar(&workersRunEvents, "workers-run", 5, "Number of workers to run events")
	flag.StringVar(&logDest, "log", "os.Stdout", "Log path, omit to log to Stdout")
	flag.Parse()

	setUpLogger()

	// TODO: Should check for wp-config.php instead?
	validatePath(&wpCliPath)
	validatePath(&wpPath)
}

func main() {
	logger.Println("Starting")

	events := make(chan Event)

	go spawnEventRetrievers(events)
	go spawnEventWorkers(events)
	keepAlive()

	logger.Println("Stopping")
}

func spawnEventRetrievers(queue chan Event) {
	for true {
		logger.Println("Spawning event-retrieval workers")

		sites, err := getSites()
		if err != nil {
			time.Sleep(getEventsLoop)
			continue
		}

		// Spawn workers to retrieve events
		workerSites := make(chan string, len(sites))

		for w := 1; w <= workersGetEvents; w++ {
			go getSiteEvents(w, workerSites, queue)
		}

		for i, site := range sites {
			if i == 0 {
				// skip header line
				continue
			}

			workerSites <- site[0]
		}

		close(workerSites)

		time.Sleep(getEventsLoop)
	}
}

func spawnEventWorkers(queue chan Event) {
	logger.Println("Spawning event workers")

	workerEvents := make(chan Event)

	for w := 1; w <= workersRunEvents; w++ {
		go runEvents(w, workerEvents)
	}

	for event := range queue {
		workerEvents <- event
	}

	close(workerEvents)

	time.Sleep(runEventsBreak)
	go spawnEventWorkers(queue)
}

func keepAlive() {
	time.Sleep(getEventsLoop)

	for true {
		// TODO: check if process should exit or something?
		// TODO: error counters?
		logger.Println("<heartbeat>")
		time.Sleep(getEventsLoop)
	}
}

func getSites() ([][]string, error) {
	siteInfo, err := getInstanceInfo()
	if err != nil {
		return make([][]string,0), err
	}

	if siteInfo.Multisite == 1 {
		sites, err := getWpCliOutput([]string{"site", "list", "--fields=url", "--archived=false", "--deleted=false", "--spam=false"})

		if err != nil {
			sites = make([][]string,0)
		}

		return sites, err
	} else {
		// Mock for single site
		sites    := make([][]string,2)
		sites[0] = make([]string,0)
		sites[0] = append(sites[0], "url")
		sites[1] = make([]string,0)
		sites[1] = append(sites[1], siteInfo.Siteurl)

		return sites, nil
	}
}

func getSiteEvents(workerId int, sites <-chan string, queue chan<- Event) {
	for site := range sites {
		logger.Printf("getEvents-%d processing %s", workerId, site)

		subcommand := []string{"cron-control", "orchestrate", "list-due-batch"}

		if len(site) > 7 {
			subcommand = append(subcommand, fmt.Sprintf("--url=%s", site))
		}

		siteEvents, err := getWpCliOutput(subcommand)
		if err != nil || len(siteEvents) < 1 {
			return
		}

		for i, event := range siteEvents {
			if i == 0 {
				// skip header line
				continue
			}

			queue <- Event{url: site, timestamp: event[0], action: event[1], instance: event[2]}
		}

		time.Sleep(getEventsBreak)
	}
}

func runEvents(workerId int, events <-chan Event) {
	for event := range events {
		subcommand := []string{"cron-control", "orchestrate", "run", fmt.Sprintf("--timestamp=%s", event.timestamp), fmt.Sprintf("--action=%s", event.action), fmt.Sprintf("--instance=%s", event.instance)}
		if len(event.url) > 7 {
			subcommand = append(subcommand, fmt.Sprintf("--url=%s", event.url))
		}

		runWpCliCmd(subcommand)

		logger.Printf("runEvents-%d finished job %s|%s|%s for %s", workerId, event.timestamp, event.action, event.instance, event.url)

		time.Sleep(runEventsBreak)
	}
}

func getInstanceInfo() (SiteInfo, error) {
	raw, err := runWpCliCmd([]string{"cron-control", "orchestrate", "get-info","--format=json"})
	if err != nil {
		return SiteInfo{}, err
	}

	jsonRes := make([]SiteInfo,0)
	if err = json.Unmarshal([]byte(raw), &jsonRes); err != nil {
		return SiteInfo{}, err
	}

	return jsonRes[0], nil
}

func getWpCliOutput(subcommand []string) ([][]string, error) {
	subcommand = append(subcommand, "--format=csv")

	raw, err := runWpCliCmd(subcommand)
	if err != nil {
		errOut := make([][]string,1)
		errOut[0] = append(errOut[0], raw)
		return errOut, err
	}

	rawRead := csv.NewReader(strings.NewReader(raw))
	data, err := rawRead.ReadAll()
	if err != nil {
		return make([][]string,0), err
	}

	return data, nil
}

func runWpCliCmd(subcommand []string) (string, error) {
	subcommand = append(subcommand, "--allow-root", "--quiet", fmt.Sprintf("--path=%s", wpPath))

	wpCli := exec.Command(wpCliPath, subcommand...)
	wpOut, err := wpCli.CombinedOutput()
	wpOutStr := string(wpOut)

	if err != nil {
		return wpOutStr, err
	}

	return wpOutStr, nil
}

func setUpLogger() {
	logOpts := log.Ldate | log.Ltime | log.LUTC | log.Lshortfile

	if logDest == "os.Stdout" {
		logger = log.New(os.Stdout, "DEBUG: ", logOpts)
	} else {
		path, err := filepath.Abs(logDest)
		if err != nil {
			logger.Fatal(err)
		}

		logFile, err := os.OpenFile(path, os.O_WRONLY | os.O_CREATE | os.O_APPEND, 0644)
		if err != nil {
			log.Fatal(err)
		}

		logger = log.New(logFile, "", logOpts)
	}
}

func validatePath(path *string) {
	if len(*path) > 1 {
		var err error
		*path, err = filepath.Abs(*path)

		if err != nil {
			fmt.Printf("Error: %s", err.Error())
			os.Exit(3)
		}

		if _, err = os.Stat(*path); os.IsNotExist(err) {
			usage()
		}
	} else {
		usage()
	}
}

func usage() {
	flag.Usage()
	os.Exit(3)
}
