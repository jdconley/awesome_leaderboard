<?php
require_once 'RestController.php';
require_once 'Predis.php';

/*
 * A really naive leaderboard. But it would probably scale to any normal (read: Non-Zynga) Facebook game size with some good hardware.
 * If it got much more complicated than or if we needed the data without the web service, I would pull out a Model separately.
 * But at less than 400 LoC and this single purpose, I don't think it matters.
 *
 * This is very much designed to do all the hard work at write-time.
 * If you want more data than is currently exposed by the interface, you would have to run a job and iterate over all the score data.
 *
 * Before hitting production I'd add local in-memory caching to these methods as well to avoid the network and serialization overhead.
 * F3 has built-in modules for it. But didn't have the time right now.
 */
class LeaderboardController extends RestController
{
	//TODO: Usually I'd set this up to support multiple leaderboards by taking an
	//extra parameter on every endpoint. In this case it would be a matter of
	//namespacing the game/leaderbaord name to these key constants wherever they're
	//used through some getKey abstraction.
	//You'd probably want to use different Redis masters for different leaderboards as well.
	const LEADERBOARD_SET = 'leaderboard';
	const TOTAL_PLAYER_COUNT = 'player_count';
	const LEADERBOARD_WEEKLY_IMPROVEMENT_PREFIX = 'leaderboard_weekly_improvement_';
	const PLAYER_COUNT_TODAY_PREFIX = 'player_count_today_';
	const PLAYER_SCORE_PREFIX = 'player_scores_';
	const PLAYER_DATA_PREFIX = 'player_data_';
	const PLAYER_PLAYED_TODAY_PREFIX = 'player_played_today_';

	public function demo()
	{
		$now = time();
		$redis = $this->getRedis();
		$totalPlayers = $redis->get(LeaderboardController::TOTAL_PLAYER_COUNT);
		$today = $this->getStartOfDayTime($now);
		$todayPlayers = $redis->get(LeaderboardController::PLAYER_COUNT_TODAY_PREFIX . $today);
		$top10 = $this->getTop(10);
		$mostImprovedTop10 = $this->getMostImproved(10, $this->getStartOfWeekTime($now));

		F3::set('top10', $top10);
		F3::set('top10_weekly_improved', $mostImprovedTop10 == NULL ? array() : $mostImprovedTop10);
		F3::set('total_players', $totalPlayers);
		F3::set('today_players', $todayPlayers);

		echo F3::render("welcome.htm");
	}

	public function getTop($requestedCount)
	{
		$redis = $this->getRedis();
		$topPlayers = $redis->zrevrange(LeaderboardController::LEADERBOARD_SET, 0, $requestedCount-1, 'withscores');
		$result = array();
		$rank = 0;
		foreach ($topPlayers as $rankedScore) {
			array_push($result, array('uid'=>$rankedScore[0],'score'=>$rankedScore[1], 'rank'=>++$rank));
		}
		return $result;
	}

	public function top()
	{
		$requestedCount = $this->getOptionalParam('count', 10);
		$topPlayers = $this->getTop($requestedCount);
		parent::output($topPlayers);
	}

	public function playersCount()
	{
		$redis = $this->getRedis();
		$count = $redis->get(LeaderboardController::TOTAL_PLAYER_COUNT);

		parent::output($count);
	}

	public function playersCountToday()
	{
		$redis = $this->getRedis();
		$today = $this->getStartOfDayTime(time());
		$count = $redis->get(LeaderboardController::PLAYER_COUNT_TODAY_PREFIX . $today);

		parent::output($count);
	}

	public function mostImproved()
	{
		$requestedCount = $this->getOptionalParam('count', 10);
		$requestedWeek = $this->getOptionalParam('week', $this->getStartOfWeekTime(time()));

		$topPlayers = $this->getMostImproved($requestedCount, $requestedWeek);
		parent::output($topPlayers);
	}

	public function getMostImproved($requestedCount, $requestedWeek)
	{
		$redis = $this->getRedis();
		$key = LeaderboardController::LEADERBOARD_WEEKLY_IMPROVEMENT_PREFIX . $requestedWeek;
		$topPlayers = $redis->zrevrange($key, 0, $requestedCount-1, 'withscores');

		$result = array();
		$rank = 0;
		foreach ($topPlayers as $rankedScore) {
			array_push($result, array('uid'=>$rankedScore[0],'score'=>$rankedScore[1], 'rank'=>++$rank));
		}
		return $result;
	}

	public function postScore()
	{
		//TODO: abstract this to a more generic authentication/authorization system
		$facebook = F3::get('facebook');
		if (!$facebook  || $facebook->getUser() == 0) {
			F3::error(503);
			return;
		}

		//precondition bounds checks before we use up too much more time
		$req = F3::scrub($_REQUEST);
		$score = $req['score'];
		if ($score == null || $score < 0) {
			F3::error(400);
			return;
		}

		//gather up all the info on the player
		$uid = $facebook->getUser();
		$added = time();
		$redis = $this->getRedis();
		$playerScores = $redis->lrange(LeaderboardController::PLAYER_SCORE_PREFIX . $uid, 0, -1);
		$scoreList = array();
		foreach ($playerScores as $scoreSerialized) {
			$oldScore = $this->deserialize($scoreSerialized);
			array_push($scoreList, $oldScore);
		}

		//write the update
		$this->addPlayerScore($redis, $uid, $added, $score, $scoreList);

		//return the player's current ranks on the all time and weekly-improved leaderboards
		$globalRank = $redis->zrevrank(LeaderboardController::LEADERBOARD_SET, $uid);
		$thisWeek = $this->getStartOfWeekTime($added);
		$weeklyRank = $redis->zrevrank(LeaderboardController::LEADERBOARD_WEEKLY_IMPROVEMENT_PREFIX . $thisWeek, $uid);
		$totalPlayers = $redis->get(LeaderboardController::TOTAL_PLAYER_COUNT);
		$today = $this->getStartOfDayTime($added);
		$todayPlayers = $redis->get(LeaderboardController::PLAYER_COUNT_TODAY_PREFIX . $today);
		parent::output(array('global_rank'=>$globalRank+1,'weekly_improve_rank'=>$weeklyRank+1,'total_players'=>$totalPlayers,'today_players'=>$todayPlayers));
	}

	private function addPlayerScore($redis, $uid, $added, $score, $userScores)
	{
		//First add (or not) a user
		$user = array('name'=>'User '.$uid, 'uid'=>$uid, 'added'=>$added);
		$setResult = $redis->setnx(LeaderboardController::PLAYER_DATA_PREFIX . $uid, $this->serialize($user));
		if ($setResult == 1) {
			$redis->incr(LeaderboardController::TOTAL_PLAYER_COUNT);
		}

		//How many scores has this player put in today?
		//On the first one, incr the global count for quick querying.
		//TODO: Decr these values if the transaction fails below. Otherwise we will get inconsistent counts.
		$today = $this->getStartOfDayTime($added);
		if ($redis->incr(LeaderboardController::PLAYER_PLAYED_TODAY_PREFIX . $uid . '_' . $today) == 1) {
			$redis->incr(LeaderboardController::PLAYER_COUNT_TODAY_PREFIX . $today);
		}

		//Add the score in both a batch/pipeline and a transcation.
		$pipe = $redis->pipeline();
		$pipe->multi();

		try
		{
			//Pipeline the scores for the user.
			//The scores go to a list per user.
			//The highest score for a user also goes into the leaderboard list, sorted by the score.
			//The difference between the players' scores this week go to the weekly improvement leaderboard list.
			$maxScore = 0;

			//See if this score is bigger than the biggest one the player already has.
			//At the same time, check for this week's update, so we only have to iterate once, O(n) and all.
			$thisWeek = $this->getStartOfWeekTime($added);
			$thisWeekMin = PHP_INT_MAX;
			$thisWeekMax = -1;
			foreach ($userScores as $aScore) {
				if ($aScore->added >= $thisWeek) {
					$thisWeekMax = max($aScore->score, $thisWeekMax);
					$thisWeekMin = min($aScore->score, $thisWeekMin);
				}
				$maxScore = max($maxScore, $score);
			}
			//Persist the score instance for the player.
			$scoreData = array('uid'=>$uid,'score'=>$score,'added'=>$added);
			$pipe->rpush(LeaderboardController::PLAYER_SCORE_PREFIX . $uid, $this->serialize($scoreData));

			//And, put this player's highest score on the leaderboard.
			$pipe->zadd(LeaderboardController::LEADERBOARD_SET, $maxScore, $uid);

			//see if they played more than once and improved
			$improvement = $thisWeekMax - $thisWeekMin;
			if ($improvement > -1) {
				$pipe->zadd(LeaderboardController::LEADERBOARD_WEEKLY_IMPROVEMENT_PREFIX . $thisWeek, $improvement, $uid);
			}

			//commit the transaction, and execute the pipeline.
			$pipe->exec();
			$pipe->execute();
		}
		catch (Exception $e)
		{
			$pipe->discard();
			$pipe->execute();
			echo "Error! Aborting transaction for ". $uid . '. Message: ' . $e->getMessage() + "\n.";
			throw $e;
		}
	}

	/*
	 * Generates lots of random score data in the service.
	 * This is one big method. Not going to reuse any of it right now so not breaking it up.
	 * I decided not to re-use addPlayerScore as it would be much slower with all the extra round trips to the db per player.
	 * And the time to implement passing a pipeline through to it didn't seem worth it for only a single inspired-by method.
	 */
	public function generate()
	{
		//Let this script run for a bit, and flush stuff.
		set_time_limit(600);
		@ini_set('zlib.output_compression',0);
		@ini_set('implicit_flush',1);
		@ob_end_clean();

		$requested_count = $this->getOptionalParam('count', 1000);

		//TODO: let these constants be configured via parameters
		$now = time();
		$SET_SIZE = 1000;
		$SCORE_START_TIME = $now - (60 * 60 * 24 * 7 * 2); //players and their scores are up to two weeks old
		$SCORE_END_TIME = $now;
		$SCORE_MIN = 0;
		$SCORE_MAX = 999999999;
		$SCORE_COUNT_MIN = 1;
		$SCORE_COUNT_MAX = 10;

		//This will only allow 1MM users per second to be inserted... serially, or we'll get conflicts.
		//Obviously not an issue with ID's from Facebook or other identity service.
		$STARTING_USER_ID = $now * 1000000;

		$sets = ceil($requested_count / $SET_SIZE);
		$lastSetSize = $requested_count % $SET_SIZE == 0 ? $SET_SIZE : $requested_count % $SET_SIZE;
		header('Content-type: text/plain');
		echo 'Generating ' . $requested_count . ' new users in ' . $sets . ' sets of ' . $SET_SIZE . ".\n";
		echo 'Users will have a random distribution of scores ranging from ' . $SCORE_MIN . ' to ' . $SCORE_MAX . ".\n";
		echo 'The Time of score entries will range randomly from ' . $SCORE_START_TIME . ' to ' . $SCORE_END_TIME . ".\n";
		echo 'Each user will have between ' . $SCORE_COUNT_MIN . ' and ' . $SCORE_COUNT_MAX . " scores.\n";

		$redis = $this->getRedis();
		$uid = $STARTING_USER_ID;

		//Iterate on sets since we're batching the writes
		for ($i = 1; $i <= $sets; $i++) {
			echo 'Building set ' . $i . ' of ' . $sets . "...";
			$usersThisRound = $i == $sets ? $lastSetSize : $SET_SIZE;
			$pipe = $redis->pipeline();

			//Pipeline the users. This creates a batch locally and decreases round trips.
			for ($j = 1; $j <= $usersThisRound; $j++) {

				//Add each user in a transaction in the batch.
				$pipe->multi();

				try
				{
					$user = array('name'=>'User '.$uid, 'uid'=>$uid, 'added'=>$SCORE_START_TIME);
					$pipe->incr(LeaderboardController::TOTAL_PLAYER_COUNT);
					$pipe->set(LeaderboardController::PLAYER_DATA_PREFIX . $uid, $this->serialize($user));

					//Pipeline the scores for the user.
					//The scores go to a list per user.
					//The highest score for a user also goes into the leaderboard list, sorted by the score.
					//The difference between the players' scores this week go to the weekly improvement leaderboard list.
					$scoreCount = rand($SCORE_COUNT_MIN, $SCORE_COUNT_MAX);

					$maxScore = 0;
					$userScores = array();
					$playedDays = array();
					for ($k=0; $k < $scoreCount; $k++) {
						$score = rand($SCORE_MIN, $SCORE_MAX);
						$maxScore = max($maxScore, $score);
						$added = rand($SCORE_START_TIME, $SCORE_END_TIME);
						$today = $this->getStartOfDayTime($added);
						$scoreData = array('uid'=>$uid,'score'=>$score,'added'=>$added);
						array_push($userScores, $scoreData);
						$pipe->rpush(LeaderboardController::PLAYER_SCORE_PREFIX . $uid, $this->serialize($scoreData));
						$pipe->incr(LeaderboardController::PLAYER_PLAYED_TODAY_PREFIX . $uid . '_' . $today);
						if (!array_key_exists($today, $playedDays)) {
							$pipe->incr(LeaderboardController::PLAYER_COUNT_TODAY_PREFIX . $today);
							$playedDays[$today] = $playedDays;
						}
					}
					$pipe->zadd(LeaderboardController::LEADERBOARD_SET, $maxScore, $uid);

					$thisWeek = $this->getStartOfWeekTime($now);

					//find see how much they "improved" this week
					$thisWeekMin = PHP_INT_MAX;
					$thisWeekMax = -1;
					foreach ($userScores as $aScore) {
						if ($aScore['added'] >= $thisWeek) {
							$thisWeekMax = max($aScore["score"], $thisWeekMax);
							$thisWeekMin = min($aScore["score"], $thisWeekMin);
						}
					}

					//see if they played more than once and improved
					$improvement = $thisWeekMax - $thisWeekMin;
					if ($improvement > -1) {
						$pipe->zadd(LeaderboardController::LEADERBOARD_WEEKLY_IMPROVEMENT_PREFIX . $thisWeek, $improvement, $uid);
					}

					//"commit" the transaction, even though it's locally pipelined with the rest of our batch. Redis fun.
					$pipe->exec();

					if ($j % intval($usersThisRound / 10) == 0) {
						echo ($j/$usersThisRound)*100 . '%...';
					}
				}
				catch (Exception $e)
				{
					$pipe->discard();
					echo "Error! Aborting transaction for ". $uid . '. Message: ' . $e->getMessage() + "\n.";
				}

				$uid++;
			}

			echo "Persisting...";
			$pipe->execute();

			echo "Done!\n";
		}

		echo "All the way done. Have a nice day!";
	}

	private function getStartOfWeekTime($timestamp)
	{
		//From: http://www.php.net/manual/en/function.date.php#107536
		$time_passed = (date('N',$timestamp)-1)* 24 * 3600; // time since start of week in days
        $startOfWeek = mktime(0,0,0,date('m',$timestamp),date('d',$timestamp),date('Y',$timestamp)) - $time_passed;
		return $startOfWeek;
	}

	private function getStartOfDayTime($timestamp)
	{
		//From: http://www.php.net/manual/en/function.date.php#107536
        $startOfDay = mktime(0,0,0,date('m',$timestamp),date('d',$timestamp),date('Y',$timestamp));
		return $startOfDay;
	}


	private function getOptionalParam($key,$default)
	{
		$result = F3::get('PARAMS["'.$key.'"]');
		if ($result === null) {
			$req = F3::scrub($_REQUEST);
			$result = array_key_exists($key, $req);
			if ($result == null) {
				$result = $default;
			}
		}
		return $result;
	}

	private function getRedis()
	{
		//TODO: connect to a server other than localhost/default
		$redis = new Predis\Client();

		//TODO: use pooling
		$redis->connect();

		return $redis;
	}
	private function deserialize($val)
	{
		return json_decode($val);
	}
	private function serialize($val)
	{
		return json_encode($val);
	}
}
?>
