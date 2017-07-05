Cron Control Go Runner
======================

In addition to the REST API endpoints that can be used to run events, a Go-based runner is provided.

# Installation

1. Build the binary as described below.
2. Copy `init.sh` to `/etc/init/cron-control-runner`
3. To override default configuration, copy `defaults` to `/etc/default/cron-control-runner` and modify as needed
4. Run `update-rc.d cron-control-runner defaults`
5. Start the runner: `/etc/init.d/cron-control-runner start`

# Runner options

* `-cli` string
  * Path to WP-CLI binary (default "/usr/local/bin/wp")
* `-log` string
  * Log path, omit to log to Stdout (default "os.Stdout")
* `-workers-get` int
  * Number of workers to retrieve events (default 3)
* `-workers-run` int
  * Number of workers to run events (default 5)
* `-wp` string
  * Path to WordPress installation (default "/var/www/html")

# Build the binary

If building on the target system, or under the same OS as the target machine, simply:

```
go build runner.go
```

If building from a different OS:

```
env GOOS=linux go build runner.go
```

Substitute `linux` with your target OS.
