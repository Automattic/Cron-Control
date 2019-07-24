package main

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"os/exec"
	"path"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/creack/pty"
	"github.com/howeyc/fsnotify"
	"golang.org/x/crypto/ssh/terminal"
)

type WpCliProcess struct {
	Guid          string
	Cmd           *exec.Cmd
	Tty           *os.File
	Running       bool
	LogFileName   string
	BytesLogged   int64
	BytesStreamed int64
}

var (
	gGUIDttys map[string]*WpCliProcess
	padlock   *sync.Mutex
	guidRegex *regexp.Regexp

	blackListed1stLevel = []string{"admin", "cli", "config", "core", "db", "dist-archive",
		"eval-file", "eval", "find", "i18n", "scaffold", "server", "package", "profile"}

	blackListed2ndLevel = map[string][]string{
		"media":    {"regenerate"},
		"theme":    {"install", "update", "delete"},
		"plugin":   {"install", "update", "delete"},
		"language": {"install", "update", "delete"},
		"vip":      {"support-user"},
	}
)

func waitForConnect() {
	gGUIDttys = make(map[string]*WpCliProcess)
	padlock = &sync.Mutex{}

	guidRegex = regexp.MustCompile("^[a-fA-F0-9\\-]+$")
	if nil == guidRegex {
		logger.Println("Failed to compile the Guid regex")
		return
	}

	addr, err := net.ResolveTCPAddr("tcp4", "0.0.0.0:22122")
	if err != nil {
		logger.Printf("error resolving listen address: %s\n", err.Error())
		return
	}

	listener, err := net.ListenTCP("tcp4", addr)
	if err != nil {
		logger.Printf("error listening on %s: %s\n", addr.String(), err.Error())
		return
	}
	defer listener.Close()

	for {
		logger.Println("listening...")
		conn, err := listener.AcceptTCP()
		logger.Println("connection: ", conn.LocalAddr().String())
		if err != nil {
			logger.Printf("error accepting connection: %s\n", err.Error())
			continue
		}
		go authConn(conn)
	}
}

func authConn(conn *net.TCPConn) {
	elems := make([]string, 0)
	var rows, cols uint64
	var Guid string

	for {
		conn.SetReadDeadline(time.Now().Add(time.Duration(5 * time.Second.Nanoseconds())))

		logger.Println("waiting for auth data")

		bufReader := bufio.NewReader(conn)
		data, err := bufReader.ReadBytes('\n')
		if nil != err {
			logger.Printf("error handshaking: %s\n", err.Error())
			conn.Close()
			return
		}

		size := len(data)

		newlineChars := 1
		if 1 < size && 0xd == (data[size-2 : size-1])[0] {
			newlineChars = 2
		}

		if err != nil {
			logger.Printf("error reading handshake reply: %s\n", err.Error())
			conn.Close()
			return
		}

		elems = strings.Split(string(data[:size-newlineChars]), ";")

		if 5 != len(elems) {
			logger.Println("error handshake format incorrect")
			conn.Close()
			return
		}

		if len(elems[0]) != len(gRemoteToken) {
			logger.Printf("error incorrect handshake reply size: %d != %d\n", len(gRemoteToken), len(elems[0]))
			conn.Close()
			return
		}

		if !guidRegex.Match([]byte(elems[1])) {
			logger.Println("error incorrect GUID format")
			conn.Write([]byte("error incorrect GUID format"))
			conn.Close()
			return
		}

		Guid = elems[1]

		rows, err = strconv.ParseUint(elems[2], 10, 16)
		if nil != err {
			logger.Printf("error incorrect console rows setting: %s\n", err.Error())
			conn.Close()
			return
		}

		cols, err = strconv.ParseUint(elems[3], 10, 16)
		if nil != err {
			logger.Printf("error incorrect console columns setting: %s\n", err.Error())
			conn.Close()
			return
		}
		if elems[0] == gRemoteToken {
			logger.Println("handshake complete!")
			break
		}

		logger.Printf("error incorrect handshake string: %s\n", string(data[:size]))
	}

	conn.SetReadDeadline(time.Time{})
	conn.SetKeepAlivePeriod(time.Duration(30 * time.Second.Nanoseconds()))
	conn.SetKeepAlive(true)

	padlock.Lock()
	wpCliProcess, found := gGUIDttys[Guid]
	padlock.Unlock()

	if found {
		// Reattach to the running WP-CLi command
		attachWpCliCmdRemote(conn, wpCliProcess, uint16(rows), uint16(cols))
		return
	}

	// The Guid is not currently running
	wpCliCmd, err := validateAndProcessCommand(elems[4])
	if nil != err {
		logger.Println(err.Error())
		conn.Write([]byte(err.Error()))
		conn.Close()
		return
	}
	runWpCliCmdRemote(conn, Guid, uint16(rows), uint16(cols), wpCliCmd)
}

func validateAndProcessCommand(calledCmd string) (string, error) {
	if 0 == len(strings.TrimSpace(calledCmd)) {
		return "", errors.New("No WP CLI command specified")
	}

	cmdParts := strings.Fields(strings.TrimSpace(calledCmd))
	if 0 == len(cmdParts) {
		return "", errors.New("WP CLI command not sent")
	}

	for _, command := range blackListed1stLevel {
		if strings.ToLower(strings.TrimSpace(cmdParts[0])) == command {
			return "", errors.New(fmt.Sprintf("WP CLI command '%s' is not permitted\n", command))
		}
	}

	if 1 == len(cmdParts) {
		return strings.TrimSpace(cmdParts[0]), nil
	}

	for command, blacklistedMap := range blackListed2ndLevel {
		for _, subCommand := range blacklistedMap {
			if strings.ToLower(strings.TrimSpace(cmdParts[0])) == command &&
				strings.ToLower(strings.TrimSpace(cmdParts[1])) == subCommand {
				return "", errors.New(fmt.Sprintf("WP CLI command '%s %s' is not permitted\n", command, subCommand))
			}
		}
	}

	return strings.Join(cmdParts, " "), nil
}

func getCleanWpCliArgumentArray(wpCliCmdString string) ([]string, error) {
	rawArgs := strings.Fields(wpCliCmdString)
	cleanArgs := make([]string, 0)
	openQuote := false
	arg := ""

	for _, rawArg := range rawArgs {
		if idx := strings.Index(rawArg, "\""); -1 != idx {
			if idx != strings.LastIndexAny(rawArg, "\"") {
				cleanArgs = append(cleanArgs, rawArg)
			} else if openQuote {
				arg = fmt.Sprintf("%s %s", arg, rawArg)
				cleanArgs = append(cleanArgs, arg)
				arg = ""
				openQuote = false
			} else {
				arg = rawArg
				openQuote = true
			}
		} else {
			if openQuote {
				arg = fmt.Sprintf("%s %s", arg, rawArg)
			} else {
				cleanArgs = append(cleanArgs, rawArg)
			}
		}
	}

	if openQuote {
		return make([]string, 0), errors.New(fmt.Sprintf("WP CLI command is invalid: %s\n", wpCliCmdString))
	}

	return cleanArgs, nil
}

func processTCPConnectionData(conn *net.TCPConn, wpcli *WpCliProcess) {
	data := make([]byte, 8192)
	var size, written int
	var err error

	conn.SetReadBuffer(8192)
	for {
		size, err = (*conn).Read(data)

		if nil != err {
			if io.EOF == err {
				logger.Println("client connection closed")
			} else if !strings.Contains(err.Error(), "use of closed network connection") {
				logger.Printf("error reading from the client connection: %s\n", err.Error())
			}
			break
		}

		if 0 == size {
			logger.Println("ignoring data of length 0")
			continue
		}

		if 1 == size && 0x3 == data[0] {
			logger.Println("Ctrl-C received, terminating the WP-CLI and exiting")
			wpcli.Cmd.Process.Kill()
			break
		}

		if 4 < len(data) && "\xc2\x9b8;" == string(data[:4]) && 't' == data[size-1:][0] {
			cmdParts := strings.Split(string(data[4:size]), ";")

			rows, err := strconv.ParseUint(cmdParts[0], 10, 16)
			if nil != err {
				logger.Printf("error reading rows resize data from the WP CLI client: %s\n", err.Error())
				continue
			}
			cols, err := strconv.ParseUint(cmdParts[1][:len(cmdParts[1])-1], 10, 16)
			if nil != err {
				logger.Printf("error reading columns resize data from the WP CLI client: %s\n", err.Error())
				continue
			}

			err = pty.Setsize(wpcli.Tty, &pty.Winsize{Rows: uint16(rows), Cols: uint16(cols)})
			if nil != err {
				logger.Printf("error performing window resize: %s\n", err.Error())
			} else {
				logger.Printf("set new window size: %dx%d\n", rows, cols)
			}
			continue
		}

		written, err = wpcli.Tty.Write(data[:size])
		if nil != err {
			if io.EOF != err {
				logger.Printf("error writing to the WP CLI tty: %s\n", err.Error())
				break
			}
		}
		if written != size {
			logger.Println("error writing to the WP CLI tty: not enough data written")
			break
		}
	}
}

func attachWpCliCmdRemote(conn *net.TCPConn, wpcli *WpCliProcess, rows uint16, cols uint16) error {
	logger.Printf("resuming %s - rows: %d, cols: %d\n", wpcli.Guid, rows, cols)
	connectionActive := true

	err := pty.Setsize(wpcli.Tty, &pty.Winsize{Rows: uint16(rows), Cols: uint16(cols)})
	if nil != err {
		logger.Printf("error performing window resize: %s\n", err.Error())
	} else {
		logger.Printf("set new window size: %dx%d\n", rows, cols)
	}

	watcher, err := fsnotify.NewWatcher()
	if err != nil {
		conn.Write([]byte("unable to reattach to the WP CLI processs"))
		conn.Close()
		return errors.New(fmt.Sprintf("error reattaching to the WP CLI process: %s\n", err.Error()))
	}

	go func() {
		var written, read int
		var buf []byte = make([]byte, 8192)

		readFile, err := os.OpenFile(wpcli.LogFileName, os.O_RDONLY, os.ModeCharDevice)
		if nil != err {
			logger.Printf("error opening the read file for the catchup stream: %s\n", err.Error())
			conn.Close()
			return
		}
		defer readFile.Close()

		logger.Printf("Seeking %s to %d for the catchup stream", wpcli.LogFileName, wpcli.BytesStreamed)
		readFile.Seek(wpcli.BytesStreamed, 0)

	Catchup_Loop:
		for {
			if !connectionActive {
				logger.Println("client connection is closed, exiting this catchup loop")
				break Catchup_Loop
			}
			read, err = readFile.Read(buf)

			if nil != err {
				logger.Printf("error reading file for the catchup stream: %s\n", err.Error())
				break Catchup_Loop
			}

			if 0 == read {
				logger.Printf("error reading file for the stream: %s\n", err.Error())
				break Catchup_Loop
			}

			written, err = conn.Write(buf[:read])
			if nil != err {
				logger.Printf("error writing to client connection: %s\n", err.Error())
				return
			}

			atomic.AddInt64(&wpcli.BytesStreamed, int64(written))

			if wpcli.BytesStreamed == wpcli.BytesLogged {
				break Catchup_Loop
			}
		}

		// Used to monitor when the connection is disconnected or the CLI command finishes
		ticker := time.Tick(time.Duration(1 * time.Second.Nanoseconds()))

	Watcher_Loop:
		for {
			if !connectionActive || !wpcli.Running {
				logger.Println("client connection is closed, exiting this watcher loop")
				break Watcher_Loop
			}
			if !wpcli.Running {
				logger.Println("WP CLI command finished, exiting this watcher loop")
				break Watcher_Loop
			}

			select {
			case <-ticker:
				// We are just tick-tocking
			case ev := <-watcher.Event:
				if ev.IsDelete() {
					break Watcher_Loop
				}
				if !ev.IsModify() {
					continue
				}

				read, err = readFile.Read(buf)
				if 0 == read {
					continue
				}

				written, err = conn.Write(buf[:read])
				if nil != err {
					logger.Printf("error writing to client connection: %s\n", err.Error())
					break Watcher_Loop
				}
				atomic.AddInt64(&wpcli.BytesStreamed, int64(written))
			case err := <-watcher.Error:
				logger.Printf("error scanning the logfile: %s", err.Error())
			}
		}

		logger.Println("closing connection at the end of the file read")
		conn.Close()
	}()

	err = watcher.Watch(wpcli.LogFileName)
	if err != nil {
		logger.Printf("error watching the logfile: %s", err.Error())
		conn.Write([]byte("unable to open the remote process log"))
		conn.Close()
		return errors.New(fmt.Sprintf("error watching the logfile: %s\n", err.Error()))
	}

	processTCPConnectionData(conn, wpcli)
	conn.Close()
	connectionActive = false
	return nil
}

func runWpCliCmdRemote(conn *net.TCPConn, Guid string, rows uint16, cols uint16, wpCliCmdString string) error {
	cmdArgs := make([]string, 0)
	cmdArgs = append(cmdArgs, strings.Fields("--path="+wpPath)...)

	cleanArgs, err := getCleanWpCliArgumentArray(wpCliCmdString)
	if nil != err {
		conn.Write([]byte("WP CLI command is invalid"))
		conn.Close()
		return errors.New("WP CLI command is invalid")
	}

	cmdArgs = append(cmdArgs, cleanArgs...)

	cmd := exec.Command("/usr/local/bin/wp", cmdArgs...)
	cmd.Env = append(os.Environ(), "TERM=xterm-256color")

	logger.Printf("launching %s - rows: %d, cols: %d, args: %s\n", Guid, rows, cols, strings.Join(cmdArgs, " "))

	var logFileName string
	if "os.Stdout" == logDest {
		logFileName = fmt.Sprintf("/tmp/wp-cli-%s", Guid)
	} else {
		logDir := path.Dir(logDest)
		logFileName = fmt.Sprintf("%s/wp-cli-%s", logDir, Guid)
	}

	if _, err := os.Stat(logFileName); nil == err {
		logger.Printf("Removing existing GUid logfile %s", logFileName)
		os.Remove(logFileName)
	}

	logger.Printf("Creating the logfile %s", logFileName)
	logFile, err := os.OpenFile(logFileName, os.O_APPEND|os.O_WRONLY|os.O_CREATE|os.O_SYNC, 0666)
	if nil != err {
		conn.Write([]byte("unable to launch the remote WP CLI process: " + err.Error()))
		conn.Close()
		return errors.New(fmt.Sprintf("error creating the WP CLI log file: %s\n", err.Error()))
	}

	watcher, err := fsnotify.NewWatcher()
	if err != nil {
		conn.Write([]byte("unable to launch the remote WP CLI process: " + err.Error()))
		logFile.Close()
		conn.Close()
		return errors.New(fmt.Sprintf("error launching the WP CLI log file watcher: %s\n", err.Error()))
	}

	tty, err := pty.StartWithSize(cmd, &pty.Winsize{Rows: rows, Cols: cols})
	if nil != err {
		conn.Write([]byte("unable to launch the remote WP CLI process."))
		logFile.Close()
		conn.Close()
		return errors.New(fmt.Sprintf("error launching the WP CLI process: %s\n", err.Error()))
	}

	defer func() {
		cmd.Process.Kill()
		cmd.Process.Wait()
		tty.Close()
	}()

	padlock.Lock()
	wpcli := &WpCliProcess{}
	wpcli.Guid = Guid
	wpcli.Cmd = cmd
	wpcli.BytesLogged = 0
	wpcli.BytesStreamed = 0
	wpcli.Tty = tty
	wpcli.LogFileName = logFileName
	wpcli.Running = true
	gGUIDttys[Guid] = wpcli
	padlock.Unlock()

	prevState, err := terminal.MakeRaw(int(tty.Fd()))
	if nil != err {
		conn.Write([]byte("unable to initialize the remote WP CLI process."))
		conn.Close()
		return errors.New(fmt.Sprintf("error initializing the WP CLI process: %s\n", err.Error()))
	}
	defer func() { _ = terminal.Restore(int(tty.Fd()), prevState) }()

	go func() {
		var written, read int
		var buf []byte = make([]byte, 8192)

		readFile, err := os.OpenFile(logFileName, os.O_RDONLY, os.ModeCharDevice)
		if nil != err {
			logger.Printf("error opening the read file for the stream: %s\n", err.Error())
			conn.Close()
			return
		}

	Exit_Loop:
		for {
			select {
			case ev := <-watcher.Event:
				if ev.IsDelete() {
					break Exit_Loop
				}
				if !ev.IsModify() {
					continue
				}

				read, err = readFile.Read(buf)
				if nil != err {
					logger.Printf("error reading the log file: %s\n", err.Error())
					continue
				}
				if 0 == read {
					continue
				}

				if nil == conn {
					logger.Println("client connection is closed, exiting this watcher loop")
					break Exit_Loop
				}

				written, err = conn.Write(buf[:read])
				atomic.AddInt64(&wpcli.BytesStreamed, int64(written))

				if nil != err {
					logger.Printf("error writing to client connection: %s\n", err.Error())
					break Exit_Loop
				}

			case err := <-watcher.Error:
				logger.Printf("error scanning the logfile %s: %s", logFileName, err.Error())
			}
		}

		if nil != conn {
			logger.Println("closing the connection on exit of the file read")
			conn.Close()
		}
	}()

	err = watcher.Watch(logFileName)
	if err != nil {
		logger.Fatal(err)
	}

	go func() {
		var written, read int
		var err error
		var buf []byte = make([]byte, 8192)

		for {
			if _, err = tty.Stat(); nil != err {
				// This is because the command has been terminated
				break
			}
			read, err = tty.Read(buf)
			atomic.AddInt64(&wpcli.BytesLogged, int64(read))

			if nil != err {
				if io.EOF != err {
					logger.Printf("error reading WP CLI tty output: %s\n", err.Error())
				}
				break
			}

			if 0 == read {
				continue
			}

			written, err = logFile.Write(buf[:read])
			if nil != err {
				logger.Printf("error writing to logfle: %s\n", err.Error())
				break
			}
			if written != read {
				logger.Printf("error writing to logfile, read %d and only wrote %d\n", read, written)
				break
			}
		}
		logFile.Close()
	}()

	go func() {
		processTCPConnectionData(conn, wpcli)

		conn.Close()
		conn = nil
		logger.Printf("%+v\n", wpcli)
	}()

	cmd.Process.Wait()

	padlock.Lock()
	wpcli.Running = false
	padlock.Unlock()

	if nil != cmd.Process {
		logger.Println("terminating the wp command")
		cmd.Process.Kill()
	}

	for {
		if wpcli.BytesStreamed >= wpcli.BytesLogged || nil == conn {
			break
		}
		time.Sleep(time.Duration(1 * time.Second.Nanoseconds()))
		logger.Printf("waiting for remaining bytes to be written to a client: at %d - have %d\n", wpcli.BytesStreamed, wpcli.BytesLogged)
	}

	padlock.Lock()
	delete(gGUIDttys, Guid)
	padlock.Unlock()

	if nil != conn {
		logger.Println("closing the connection at the end")
		conn.Close()
	}
	return nil
}
