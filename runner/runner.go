package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

type SiteInfo struct {
	Multisite int
	Siteurl   string
}

type Site struct {
	Url string
}

type Event struct {
	Url       string
	Timestamp int
	Action    string
	Instance  string
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

		workerSites := make(chan string, len(sites))

		for w := 1; w <= workersGetEvents; w++ {
			go queueSiteEvents(w, workerSites, queue)
		}

		for _, site := range sites {
			workerSites <- site.Url
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

	logger.Println("Event workers died, exiting")
	os.Exit(1)
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

func getSites() ([]Site, error) {
	siteInfo, err := getInstanceInfo()
	if err != nil {
		return make([]Site, 0), err
	}

	if siteInfo.Multisite == 1 {
		sites, err := getMultisiteSites()
		if err != nil {
			sites = make([]Site, 0)
		}

		return sites, err
	} else {
		// Mock for single site
		sites := make([]Site, 0)
		sites = append(sites, Site{Url: siteInfo.Siteurl})

		return sites, nil
	}
}

func getInstanceInfo() (SiteInfo, error) {
	raw, err := runWpCliCmd([]string{"cron-control", "orchestrate", "get-info", "--format=json"})
	if err != nil {
		return SiteInfo{}, err
	}

	jsonRes := make([]SiteInfo, 0)
	if err = json.Unmarshal([]byte(raw), &jsonRes); err != nil {
		return SiteInfo{}, err
	}

	return jsonRes[0], nil
}

func getMultisiteSites() ([]Site, error) {
	raw, err := runWpCliCmd([]string{"site", "list", "--fields=url", "--archived=false", "--deleted=false", "--spam=false", "--format=json"})
	if err != nil {
		logger.Println(fmt.Sprintf("%+v\n", err))
		return make([]Site, 0), err
	}

	jsonRes := make([]Site, 0)
	if err = json.Unmarshal([]byte(raw), &jsonRes); err != nil {
		logger.Println(fmt.Sprintf("%+v\n", err))
		return make([]Site, 0), err
	}

	return jsonRes, nil
}

func queueSiteEvents(workerId int, sites <-chan string, queue chan<- Event) {
	for site := range sites {
		logger.Printf("getEvents-%d processing %s", workerId, site)

		siteEvents, err := getSiteEvents(site)
		if err != nil {
			time.Sleep(getEventsBreak)
			continue
		}

		for _, event := range siteEvents {
			event.Url = site
			queue <- event
		}

		time.Sleep(getEventsBreak)
	}
}

func getSiteEvents(site string) ([]Event, error) {
	raw, err := runWpCliCmd([]string{"cron-control", "orchestrate", "list-due-batch", fmt.Sprintf("--url=%s", site), "--format=json"})
	if err != nil {
		return make([]Event, 0), err
	}

	siteEvents := make([]Event, 0)
	if err = json.Unmarshal([]byte(raw), &siteEvents); err != nil {
		return make([]Event, 0), err
	}

	return siteEvents, nil
}

func runEvents(workerId int, events <-chan Event) {
	for event := range events {
		subcommand := []string{"cron-control", "orchestrate", "run", fmt.Sprintf("--timestamp=%d", event.Timestamp), fmt.Sprintf("--action=%s", event.Action), fmt.Sprintf("--instance=%s", event.Instance), fmt.Sprintf("--url=%s", event.Url)}

		runWpCliCmd(subcommand)

		logger.Printf("runEvents-%d finished job %d|%s|%s for %s", workerId, event.Timestamp, event.Action, event.Instance, event.Url)

		time.Sleep(runEventsBreak)
	}
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
