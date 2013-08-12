<?php

	define('A', 3);
	define('B', 4);
	define('C', 2);

	function atow($p)
	{
		$a = "";

		$fd = fopen($p, 'r');
		while (($c = fgetc($fd)) !== false)
		{
			$b = decbin(ord($c));
			$cc = strlen($b);
			for ($i = 0 ; $i < $cc ; ++$i) $a .= $b{$i} ? chr(A) : chr(B);
			$a .= chr(C);
		}

		return $a;
	}

	function wtoa($p)
	{
		$a = "";

		$fd = fopen($p, 'r');
		while (($c = fgetc($fd)) !== false)
		{
			if ($c == chr(C))
			{
				$n = '';
				$cc = strlen($b);
				for ($i = 0 ; $i < $cc ; ++$i) $n .= $b{$i} == chr(A) ? '1' : '0';
				$by = bindec($n);
				$a .= chr($by);
				$b = '';
			} 
			else
			{
				$b .= $c;
			}
		}

		return $a;
	}

	$str = atow('fd.php');
	file_put_contents('atow.b', $str);

	$str = wtoa('atow.b');
	file_put_contents('wtoa.b', $str);
