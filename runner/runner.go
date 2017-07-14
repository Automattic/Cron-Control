package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"math/rand"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"sync/atomic"
	"time"
)

type SiteInfo struct {
	Multisite int
	Siteurl   string
	Disabled  int
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
	wpNetwork int
	wpPath    string

	workersGetEvents int
	workersRunEvents int

	disabledLoopCount uint64

	logger  *log.Logger
	logDest string
)

const getEventsLoop time.Duration  = time.Minute
const getEventsBreak time.Duration = time.Second
const runEventsBreak time.Duration = time.Second * 10

func init() {
	flag.StringVar(&wpCliPath, "cli", "/usr/local/bin/wp", "Path to WP-CLI binary")
	flag.IntVar(&wpNetwork, "network", 1, "WordPress network ID")
	flag.StringVar(&wpPath, "wp", "/var/www/html", "Path to WordPress installation")
	flag.IntVar(&workersGetEvents, "workers-get", 1, "Number of workers to retrieve events")
	flag.IntVar(&workersRunEvents, "workers-run", 5, "Number of workers to run events")
	flag.StringVar(&logDest, "log", "os.Stdout", "Log path, omit to log to Stdout")
	flag.Parse()

	setUpLogger()

	// TODO: Should check for wp-config.php instead?
	validatePath(&wpCliPath)
	validatePath(&wpPath)
}

func main() {
	logger.Printf("Starting with %d event-retreival worker(s) and %d event worker(s)", workersGetEvents, workersRunEvents)
	sig := make(chan os.Signal, 1)
	signal.Notify(sig, os.Interrupt)
	signal.Notify(sig, os.Kill)

	sites  := make(chan Site)
	events := make(chan Event)

	go spawnEventRetrievers(sites, events)
	go spawnEventWorkers(events)
	go retrieveSitesPeriodically(sites)
	go heartbeat()

	caughtSig := <-sig
	close(events)
	time.Sleep(time.Minute)
	logger.Printf("Stopping, got signal %s", caughtSig)
}

func spawnEventRetrievers(sites <-chan Site, queue chan<- Event) {
	for w := 1; w <= workersGetEvents; w++ {
		go queueSiteEvents(w, sites, queue)
	}
}

func spawnEventWorkers(queue <-chan Event) {
	workerEvents := make(chan Event)

	for w := 1; w <= workersRunEvents; w++ {
		go runEvents(w, workerEvents)
	}

	for event := range queue {
		workerEvents <- event
	}

	close(workerEvents)
}

func retrieveSitesPeriodically(sites chan<- Site) {
	for true {
		siteList, err := getSites()
		if err != nil {
			time.Sleep(getEventsLoop)
			continue
		}

		for _, site := range siteList {
			sites <- site
		}

		time.Sleep(getEventsLoop)
	}
}

func heartbeat() {
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

	if run := shouldGetSites(siteInfo.Disabled); false == run {
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

func shouldGetSites(disabled int) (bool) {
	if disabled == 0 {
		atomic.SwapUint64(&disabledLoopCount, 0)
		return true;
	}

	disabledCount, now   := atomic.LoadUint64(&disabledLoopCount), time.Now()
	disabledSleep        := time.Minute * 3 * time.Duration(disabledCount)
	disabledSleepSeconds := int64(disabledSleep) / 1000 / 1000 / 1000

	if disabled > 1 && (now.Unix() + disabledSleepSeconds) > int64(disabled) {
		atomic.SwapUint64(&disabledLoopCount, 0)
	} else if disabledSleep > time.Hour {
		atomic.SwapUint64(&disabledLoopCount, 0)
	} else {
		atomic.AddUint64(&disabledLoopCount, 1)
	}

	if disabledSleep > 0 {
		logger.Printf("Automatic execution disabled, sleeping for an additional %d minutes", disabledSleepSeconds / 60)
		time.Sleep(disabledSleep)
	} else {
		logger.Println("Automatic execution disabled")
	}

	return false;
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

	// Shuffle site order so that none are favored
	for i := range jsonRes {
		j := rand.Intn(i + 1)
		jsonRes[i], jsonRes[j] = jsonRes[j], jsonRes[i]
	}

	return jsonRes, nil
}

func queueSiteEvents(workerId int, sites <-chan Site, queue chan<- Event) {
	for site := range sites {
		logger.Printf("getEvents-%d processing %s", workerId, site.Url)

		siteEvents, err := getSiteEvents(site.Url)
		if err != nil {
			time.Sleep(getEventsBreak)
			continue
		}

		for _, event := range siteEvents {
			event.Url = site.Url
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
		subcommand := []string{"cron-control", "orchestrate", "run", fmt.Sprintf("--timestamp=%d", event.Timestamp), fmt.Sprintf("--action=%s", event.Action), fmt.Sprintf("--instance=%s", event.Instance), fmt.Sprintf("--url=%s", event.Url), fmt.Sprintf("--network=%d", wpNetwork)}

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
