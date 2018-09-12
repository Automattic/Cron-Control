package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"runtime"
	"time"
)

type LogMessage struct {
	Level   string `json:"level"`
	Message string `json:"message"`
	Type    string `json:"type"`
}

func (m LogMessage) toJsonString() string {
	jsonStruct, err := json.Marshal(m)
	if err != nil {
		log.Fatal(err)
	}

	return string(jsonStruct)
}

type CronRunnerLogger struct {
	Format      string
	Destination string
	Type        string
	logger      *log.Logger
}

func NewCronRunnerLogger(format, dest string) *CronRunnerLogger {
	logOpts := 0

	var logWriter logWriter
	var logger *log.Logger

	if dest == "os.Stdout" {
		if format == "json" {
			logWriter = NewLogWriter("cron-control", "cron-control-runner", false)
		} else {
			// original log format
			logWriter = NewLogWriter("cron-control", "cron-control-runner", true)
		}
		logger = log.New(logWriter, "", logOpts)
	} else {
		fileWriter := NewFileWriter(dest, "cron-control", "cron-control-runner")

		logger = log.New(fileWriter, "", logOpts)
	}

	return &CronRunnerLogger{
		format,
		dest,
		"cron-control-runner",
		logger,
	}
}

func (crl *CronRunnerLogger) Printf(format string, v ...interface{}) {
	if crl.Format == "json" {
		msgStr := fmt.Sprintf(format, v)

		// Should be using level specific functions to set the `Level` (i.e.: `Debugf()`) but we're
		// doing it this way fit with how `logger` is used in `runner.go`
		msg := LogMessage{"DEBUG", msgStr, crl.Type}

		crl.logger.Print(msg.toJsonString())
	} else {
		crl.logger.Printf(format, v)
	}
}

func (crl *CronRunnerLogger) Println(v ...interface{}) {
	crl.Printf(fmt.Sprintln(v...))
}

/**
 * Custom writer to set the format we want when logging to STDOUT
 */
type logWriter struct {
	App           string
	AppType       string
	EmulateStdLog bool
}

func NewLogWriter(app, appType string, emulateStdLog bool) logWriter {
	return logWriter{app, appType, emulateStdLog}
}

func (w logWriter) Write(bytes []byte) (int, error) {
	if w.EmulateStdLog {
		// This logic is basically to emulate the original log format
		timeStr := time.Now().UTC().Format("2018/09/30 08:45:04")

		var shortFile string
		_, file, no, ok := runtime.Caller(5)
		if ok {
			shortFile = fmt.Sprintf("%s:%d", filepath.Base(file), no)
		} else {
			shortFile = "unknown"
		}

		return fmt.Printf("DEBUG: %s %s: "+string(bytes), timeStr, shortFile)
	}

	timeStr := time.Now().UTC().Format("2006-01-02T15:04:05.999Z")
	namespace := fmt.Sprintf("%s:%s", w.App, w.AppType)

	return fmt.Printf("%s %s: "+string(bytes), timeStr, namespace)
}

/**
 * Custom writer to get the format we want when logging to a file
 */
type fileWriter struct {
	App     string
	AppType string
	File    *os.File
}

func NewFileWriter(path, app, appType string) fileWriter {
	path, err := filepath.Abs(path)
	if err != nil {
		log.Fatal(err)
	}

	logFile, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_APPEND, 0644)
	if err != nil {
		log.Fatal(err)
	}

	return fileWriter{app, appType, logFile}
}

func (f fileWriter) Write(bytes []byte) (int, error) {
	timeStr := time.Now().UTC().Format("2006-01-02T15:04:05.999Z")
	namespace := fmt.Sprintf("%s:%s", f.App, f.AppType)
	msg := fmt.Sprintf("%s %s: "+string(bytes), timeStr, namespace)

	return f.File.Write([]byte(msg))
}
