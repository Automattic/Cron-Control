#!/bin/sh
### BEGIN INIT INFO
# Provides:          cron-control-runner
# Required-Start:    $network $local_fs
# Required-Stop:
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: Runner for Cron Control events
# Description:       Automattic Inc
#### END INIT INFO

NAME=cron-control-runner
USER=www-data
DEPLOY_DIR="/var/lib/cron_control_runner"
CMD="${DEPLOY_DIR}/runner"
CMD_ARGS=""

########## Common
PATH=/sbin:/bin:/usr/sbin:/usr/bin:$PATH
SSD=start-stop-daemon
PID=/var/run/${NAME}.pid

########## Overrides
[ -f "/etc/default/$NAME" ] && . /etc/default/$NAME

start () {
    echo "Starting $NAME"
    $SSD --start --chuid $USER --pidfile $PID --make-pidfile --background -d $DEPLOY_DIR --exec $CMD -- $CMD_ARGS
    RETVAL=$?
    echo
    return $RETVAL
}

stop () {
    echo "Stopping $NAME"
    $SSD --stop --oknodo --pidfile $PID
    RETVAL=$?
    echo
    return $RETVAL
}

restart () {
    stop
    sleep 1
    start
}

case "$1" in
start)
    start
    ;;

stop)
    stop
    ;;

status)
    echo "not supported"
    ;;

restart)
    restart
    ;;

*)
    echo "Usage: $0 {start|stop|restart}"
    exit 1
esac

exit $RETVAL
