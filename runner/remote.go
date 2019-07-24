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
	"time"

	"github.com/creack/pty"
	"golang.org/x/crypto/ssh/terminal"
)

var (
	blackListed1stLevel = []string{"admin", "cli", "config", "core", "db", "dist-archive",
		"eval-file", "eval", "find", "i18n", "scaffold", "server", "package", "profile"}

	blackListed2ndLevel = map[string][]string{
		"media":    {"regenerate"},
		"theme":    {"install", "update", "delete"},
		"plugin":   {"install", "update", "delete"},
		"language": {"install", "update", "delete"},
		"vip":      {"support-user"},
	}

	guidRegex *regexp.Regexp
	padlock   *sync.Mutex
)

func waitForConnect() {
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
		logger.Print("received data:", string(data[:size]))

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

	logger.Printf("Opening logfile %s", logFileName)
	logFile, err := os.OpenFile(logFileName, os.O_APPEND|os.O_WRONLY|os.O_CREATE|os.O_SYNC, 0666)

	if nil != err {
		conn.Write([]byte("unable to launch the remote WP CLI process: " + err.Error()))
		conn.Close()
		return errors.New(fmt.Sprintf("error launching the WP CLI process: %s\n", err.Error()))
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
		logFile.Close()
		conn.Close()
	}()

	prevState, err := terminal.MakeRaw(int(tty.Fd()))
	if nil != err {
		conn.Write([]byte("unable to initialize the remote WP CLI process."))
		conn.Close()
		return errors.New(fmt.Sprintf("error initializing the WP CLI process: %s\n", err.Error()))
	}
	defer func() { _ = terminal.Restore(int(tty.Fd()), prevState) }()

	var bytesRead int = 0
	var bytesStreamed int = 0

	go func() {
		var written, read int
		var err, errTty error
		var buf []byte = make([]byte, 4096)

		for {
			read, errTty = tty.Read(buf)
			bytesRead += read
			if 0 < read {
				padlock.Lock()
				written, err = logFile.Write(buf[:read])
				bytesStreamed += written
				padlock.Unlock()

				if nil != err {
					logger.Printf("error writing to the local logfile: %s\n", err.Error())
				}

				written, err = conn.Write(buf[:read])
				if nil != err {
					logger.Printf("error writing to client connection: %s\n", err.Error())
					break
				}
				if written != read {
					logger.Printf("error writing to client connection: %s\n", err.Error())
					break
				}
			}

			if nil != errTty {
				if io.EOF != errTty {
					logger.Printf("error reading WP CLI stdout: %s\n", errTty.Error())
				}
				break
			}
		}
	}()

	go func() {
		data := make([]byte, 4096)
		var size, written int
		var err error

		conn.SetReadBuffer(4096)

		for {
			conn.SetDeadline(time.Now().Add(time.Duration(600 * time.Second.Nanoseconds())))
			size, err = conn.Read(data)

			if nil != err {
				if io.EOF == err {
					logger.Println("WP CLI client connection closed")
					conn.Close()
				} else {
					logger.Printf("error reading from the WP CLI client: %s\n", err.Error())
				}
				break
			}

			if 0 == size {
				logger.Println("ignoring data of length 0")
				continue
			}

			if 1 == size && 0x3 == data[0] {
				logger.Println("Ctrl-C received, exiting")
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

				err = pty.Setsize(tty, &pty.Winsize{Rows: uint16(rows), Cols: uint16(cols)})
				if nil != err {
					logger.Printf("error performing window resize: %s\n", err.Error())
				} else {
					logger.Printf("set new window size: %dx%d\n", rows, cols)
				}
				continue
			}

			padlock.Lock()
			written, err = logFile.Write(data[:size])
			padlock.Unlock()
			if nil != err {
				logger.Printf("error writing to the local logfile: %s\n", err.Error())
			}

			written, err = tty.Write(data[:size])
			if nil != err {
				if io.ErrClosedPipe == err {
					logger.Println("error reading from the WP CLI client: closed pipe")
					break
				}
				if io.ErrUnexpectedEOF == err {
					logger.Println("error reading from the WP CLI client: unexpected EOF")
					break
				}
				if io.EOF != err {
					panic(err)
				}
			}
			if written != size {
				logger.Println("error writing to WP CLI client: not enough data written")
				break
			}
		}

		logger.Println("terminating the wp command")
		if nil != cmd.Process {
			cmd.Process.Kill()
		}
	}()

	cmd.Process.Wait()

	for {
		if bytesStreamed >= bytesRead || nil == conn {
			break
		}
		time.Sleep(time.Duration(50 * time.Millisecond.Nanoseconds()))
	}

	return nil
}
