<?php

namespace Resque\Pool;

/**
 * Platform specific funcionality of php-resque-pool.  Handles signals in/out
 * along with wrapping a few standard php functions so they can be mocked in
 * tests.
 *
 * @package   Resque-Pool
 * @auther    Erik Bernhardson <bernhardsonerik@gmail.com>
 * @copyright (c) 2012 Erik Bernhardson
 * @license   http://www.opensource.org/licenses/mit-license.php
 */
class Platform
{
    private static $SIG_QUEUE_MAX_SIZE = 5;

    // @param Logger
    protected $logger;
    // @param bool
    private $quitOnExitSignal;

    private $sigQueue = array();
    private $trappedSignals = array();

    public function setLogger(Logger $logger = null)
    {
        $this->logger = $logger;
    }

    public function setQuitOnExitSignal($bool)
    {
        $this->quitOnExitSignal = $bool;
    }

    // exit is reserved word
    public function _exit($status = 0)
    {
        exit($status);
    }

    public function pcntl_fork()
    {
        return pcntl_fork();
    }

    public function sleep($seconds)
    {
        return sleep($seconds);
    }

    public function signalPids($pids, $sig)
    {
        if (!is_array($pids)) {
            $pids = array($pids);
        }
        foreach ($pids as $pid) {
            posix_kill($pid, $sig);
        }
    }

    public function trapSignals(array $signals)
    {
        foreach ($signals as $sig) {
            $this->trappedSignals[$sig] = true;
            pcntl_signal($sig, array($this, 'trapDeferred'));
        }
    }

    public function releaseSignals()
    {
        $noop = function() {};
        foreach (array_keys($this->trappedSignals) as $sig) {
            pcntl_signal($sig, $noop);
        }
        $this->trappedSignals = array();
    }

    // INTERNAL: called by php signal handling
    public function trapDeferred($signal)
    {
        if (count($this->sigQueue) < self::$SIG_QUEUE_MAX_SIZE) {
            if ($this->quitOnExitSignal && in_array($signal, array(SIGINT, SIGTERM))) {
                $this->log("Received $signal: short circuiting QUIT waitpid");
                $this->exit(1); // TODO: should this return a failed exit code?
            }
            $this->sigQueue[] = $signal;
        } else {
            $this->log("Ignoring SIG$signal, queue=" . json_encode($this->sigQueue, true));
        }
    }

    // @return integer
    public function numSignalsPending()
    {
        pcntl_signal_dispatch();

        return count($this->sigQueue);
    }

    // @return integer|null
    public function nextSignal()
    {
        // this will queue up signals into $this->sigQueue
        pcntl_signal_dispatch();

        return array_shift($this->sigQueue);
    }

    // @param  bool            $wait When non-false and there are no dead children, wait for the next one
    // @return [int, int]|null Returns either the pid and exit code of a dead child process, or null
    public function nextDeadChild($wait = false)
    {
        $wpid = pcntl_waitpid(-1, $status, $wait === false ? WNOHANG : 0);
        // 0 is WNOHANG and no dead children, -1 is no children exist
        if ($wpid === 0 || $wpid === -1) {
            return null;
        }

        $exit = pcntl_wexitstatus($status);

        return array($wpid, $exit);
    }

    protected function log($msg)
    {
        if ($this->logger) {
            $this->logger->log($msg);
        }
    }
}
