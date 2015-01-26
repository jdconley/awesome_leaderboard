# Simple PHP Leaderboard
I created this leaderboard service as part of an interview process some years ago. I found the code lying around on a backup and thought I might as well publish it. It should be fairly performant and usable for any game developer. It's written in PHP using the [Fat Free Framework](http://fatfreeframework.com) for routing and makes extensive use of [Redis](http://redis.io/) for fast list operations and persistence.

## Getting it running
1. Startup Redis on default port on localhost
2. Point your PHP 5.3 server, I prefer [nginx](http://nginx.org/) + [PHP-FPM](http://php-fpm.org/), at the 'public' directory. The rest of the files are not meant to be published.
3. Browse to / and follow instructions. This page calls all of the RESTful functions in the leaderboard.

## The challenge
Here is the coding challenge I received, so you have some context as to why things are the way they are...

Create a RESTful service in PHP that would allow a game to post a user's score for ranking on a leaderboard. Assume the player of the game will be coming from a Facebook application so you will be passed a Facebook "signed_request" variable with the information about the user along with the user's score. Decode the signed_request to get the user's unique id. Design a schema for storing the data for the player and the score information.

Build a function to populate the database tables with 1,000,000 rows of sample data.

Build a report that efficiently answers the following questions:
 - How many total players are there?
 - How many people played the game today?
 - List the top 10 players (by score)
 - List the top 10 players who improved their score over the course of the week (the difference between their high-score at the start of the week and now)

You can use the following Facebook application data and signed request for a user:

  appId: 126767144061773
  secret: 21db65a65e204cca7b5afcbad91fea59
  signed_request: cjv1NZlSRCthYq9rAyWEidD7QE98p0PKZvVwpQ7gPwg.eyJhbGdvcml0aG0iOiJITUFDLVNIQTI1NiIsImV4cGlyZXMiOjEzMjI4NTYwMDAsImlzc3VlZF9hdCI6MTMyMjg1MDc1NCwib2F1dGhfdG9rZW4iOiJBQUFCelMwYVhTMDBCQUlob0I1bmhrYnZJU0xLSGpNb3ZIN2ZTTmMzWkFxbnVNT2NvYmpJUHoxNGFmWXV1dzBkbkZzeVpBV2JHU2MycXZBakdjRzZUQ1RWZzBLOUVGUWJ5WkJwNTU0ZXE5M2FTWkFXZXpVeEYiLCJ1c2VyIjp7ImNvdW50cnkiOiJ1cyIsImxvY2FsZSI6ImVuX1VTIiwiYWdlIjp7Im1pbiI6MjF9fSwidXNlcl9pZCI6IjEwMDAwMzI5MTY2MTkwOSJ9
