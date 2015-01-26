<?php
class RestController
{
	public function output($data)
	{
		$outputFormat = array_key_exists("format", $_REQUEST) ? $_REQUEST["format"] : 'json';
		if ($outputFormat == 'json')
		{
			header("Content-type: application/json");
			echo json_encode($data);
		} else
		{
			//TODO: Support other formats like XML, or something...
			header("Content-type: application/json");
			echo json_encode($data);
		}
	}
}
?>
