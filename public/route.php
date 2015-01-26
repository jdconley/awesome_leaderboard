<?php

//If you're not Familiar with F3, check out the docs.
//Most of the work is in the LeaderboardController for this little app.
//http://fatfreeframework.com/routing-engine

set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__.'/../lib');
require_once 'f3/base.php';
require_once 'fb/facebook.php';
require_once 'Predis.php';

F3::set('DEBUG',1);
F3::set('UI',__DIR__.'/../templates/');
F3::set('AUTOLOAD',__DIR__.'/../controllers/');

//Mocked up request data
if (!isset($_REQUEST['signed_request'])) {
	$_REQUEST['signed_request'] = "cjv1NZlSRCthYq9rAyWEidD7QE98p0PKZvVwpQ7gPwg.eyJhbGdvcml0aG0iOiJITUFDLVNIQTI1NiIsImV4cGlyZXMiOjEzMjI4NTYwMDAsImlzc3VlZF9hdCI6MTMyMjg1MDc1NCwib2F1dGhfdG9rZW4iOiJBQUFCelMwYVhTMDBCQUlob0I1bmhrYnZJU0xLSGpNb3ZIN2ZTTmMzWkFxbnVNT2NvYmpJUHoxNGFmWXV1dzBkbkZzeVpBV2JHU2MycXZBakdjRzZUQ1RWZzBLOUVGUWJ5WkJwNTU0ZXE5M2FTWkFXZXpVeEYiLCJ1c2VyIjp7ImNvdW50cnkiOiJ1cyIsImxvY2FsZSI6ImVuX1VTIiwiYWdlIjp7Im1pbiI6MjF9fSwidXNlcl9pZCI6IjEwMDAwMzI5MTY2MTkwOSJ9";
}

$facebook = new Facebook(array(
	'appId'  => '126767144061773',
	'secret' => '21db65a65e204cca7b5afcbad91fea59',
));

//Facebook in the global context so it can be read from any action.
F3::set('facebook', $facebook);

//Not Strictly RESTful, but I like the interface.
F3::route('GET /player/count', 'LeaderboardController->playersCount');
F3::route('GET /player/count/today', 'LeaderboardController->playersCountToday');
F3::route('GET /top', 'LeaderboardController->top');
F3::route('GET /top/@count', 'LeaderboardController->top');
F3::route('GET /most_improved', 'LeaderboardController->mostImproved');
F3::route('GET /most_improved/@week/@count', 'LeaderboardController->mostImproved');
F3::route('POST /score', 'LeaderboardController->postScore');

F3::route('POST /generate', 'LeaderboardController->generate');
F3::route('POST /generate/@count', 'LeaderboardController->generate');
F3::route('POST /flush', function(){
	$redis = new Predis\Client();
	echo $redis->flushdb();
});

F3::route('GET /', 'LeaderboardController->demo');

F3::route('GET /debug', function() use(&$facebook) {
	//Did this so I could make sure this was a real signed request and not a trick, and I forgot the format of the object from facebook.
	echo json_encode($facebook);
});

F3::run();

?>
