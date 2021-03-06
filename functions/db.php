<?php

/* --------- Database connection/query helper functions --------- */

function dbConn() { // Returns database link
	return mysqli_connect(DBHOST,DBUSER,DBPASS,DBNAME);
}

function dbQuery($query) { // Returns result of query, logs error and returns false on failure
	$db = dbConn();
	$result = mysqli_query($db,$query);
	if($result !== false) {
		return($result);
	}
	error_log("MySQL Query Error: " . mysqli_error($db)); 
	return(false);
}

function dbQueryId($query) { // Returns insert id of query
	$db = dbConn();
	$result = mysqli_query($db,$query);
	if($result != false) {
		return(mysqli_insert_id($db));
	}
	error_log("MySQL Query Error: " . mysqli_error($db));
	return(false);
}


function dbFirstResult($query) { // Returns first row of query result as indexed array
	$result = dbQuery($query);
	if($result === false) { return(false); }
	$row = mysqli_fetch_array($result);
	return $row[0];
}

function dbFirstResultAssoc($query) { // Returns first row of query result as associative array
	$result = dbQuery($query);
	if($result === false) { return(false); }
	$row = mysqli_fetch_assoc($result);
	return $row;
}


function dbResultArray($query) { // Returns query result as associative array
	$result = dbQuery($query);
	if($result === false) { return(false); }
	while($row = mysqli_fetch_assoc($result)) {
		$output[] = $row;
	}
	return($output);
}

function dbResultExists($query) { // Returns true if a result is found, false if it isn't
	$result = dbQuery($query);
	$row = mysqli_fetch_array($result);
	if(!empty($row)) { return(true); }
	return(false);
}

function dbRowCount($query) { // Returns the number of rows in the query result
	$result = dbQuery($query);
	if($result === false) { return(false); }
	return(mysqli_num_rows($result));
}

function dbEscape($string) { // Returns escaped string to prevent SQL injection attacks
	$db = dbConn();
	return(mysqli_real_escape_string($db,$string));
}

function dbEscapeArray($array) { // Returns escaped array to prevent SQL injection attacks
	return(array_map("dbEscape",$array));
}


/* --------- User table functions --------- */

function getUsername($id) { // Returns name of user with given id
	$id = intval($id);
	return(dbFirstResult("SELECT username FROM users WHERE id=$id"));
}

function getUserid($username) { // Returns id of user with given name
	$username = dbEscape($username);
	return(dbFirstResult("SELECT id FROM users WHERE username='$username'"));
}

function getPassword($username) { // Returns password hash of user with given name
	$username = dbEscape($username);
	return(dbFirstResult("SELECT password FROM users WHERE username='$username'"));
}

function userExists($parameter,$value) { // Checks if a user exists, either by id, email or username
	$value = dbEscape($value);
	return(dbResultExists("SELECT id FROM users WHERE $parameter='$value'"));
}

function registerUser($input) { // Writes user info to database on successful registration, returning the user id
	$input = dbEscapeArray($input);
	$salt = generateSalt(); 		
	$hash = hashPassword($input[password],$salt);
	$insert = dbQueryId("INSERT INTO users (username,email,password) VALUES ('$input[username]','$input[email]','$hash')");
	return($insert);
}

function login($sessionid) { // Store session ID in user table
	$sessionid = dbEscape($sessionid);
	$_SESSION[id] = intval($_SESSION[id]);
	if(dbQuery("UPDATE users SET sessionid='$sessionid' WHERE id=$_SESSION[id]")) { return true; }
}

function logout() { // Remove session ID from user table and destroy session
	$_SESSION[id] = intval($_SESSION[id]);	
	if(userExists("id",$_SESSION[id])) { // Make sure the user id exists
		session_regenerate_id(); 
		session_destroy();
		return false; 
	}

	if(dbQuery("UPDATE users SET sessionid='' WHERE id=$_SESSION[id]")) {
		session_regenerate_id();
		session_destroy();
		return true;
	}
}

function checkLogin($userid) { // Check session id against user table, logout if the ID is different
	$userid = intval($userid);
	if($userid != 0) {
		if(dbResultExists("SELECT id FROM users WHERE id=$userid AND sessionid='".session_id()."'")) {
			return true;
		}
	logout();
	}
	return false;
}

function getUser($userid=0) { // Returns an array containing user info, comment/link counts and points
	$userid = intval($userid);
	if($userid != 0) { $where = "WHERE users.id=$userid"; }
	$query = "
		SELECT users.id, username, IFNULL(c.count,0) AS comments, IFNULL(l.count,0) AS links, p.points, registered FROM users 
		LEFT JOIN (
			SELECT userid, COUNT(*) AS count from comments WHERE deleted!=1 GROUP BY userid
		) 
		AS c ON users.id=c.userid LEFT JOIN (
			SELECT user, COUNT(*) AS count FROM links GROUP BY user
		) 
		AS l ON users.id=l.user	LEFT JOIN (
			SELECT l.id, (IFNULL(IFNULL(l.links,0)+IFNULL(c.comments,0),0)) AS points 
			FROM (
				SELECT users.id AS id, sum(v.vote) as links FROM users 
				LEFT JOIN links ON links.user=users.id 
				LEFT JOIN votes as v ON v.subjectid=links.id AND v.type='link' GROUP BY id
			) 
			as l JOIN (
				SELECT users.id AS id, sum(v.vote) as comments FROM users 
				LEFT JOIN comments ON comments.userid=users.id 
				LEFT JOIN votes as v ON v.subjectid=comments.id AND v.type='comment' GROUP BY id
			) 
			as c ON l.id=c.id
		) 
		AS p ON p.id=users.id $where ORDER BY links DESC, comments DESC LIMIT 5";
	if($userid == 0) { return(dbResultArray($query)); }
	return(dbFirstResultAssoc($query));
}


/* --------- Link table functions --------- */

function getLink($id) { // Fetch a single link by id
	$_SESSION[id] = intval($_SESSION[id]);
	if($_SESSION[id] != 0) { // If we're logged in, include our vote in the query
		$myvote = "IFNULL(v.vote,0) AS myvote,";
		$myvotejoin = "LEFT JOIN votes AS v ON l.id=v.subjectid AND v.type='link' AND v.userid=$_SESSION[id]";
	}
	$query = "
	SELECT l.*,u.username AS username,IFNULL(r.votes,0) AS votes,IFNULL(t.points,0) AS points, $myvote c.comments FROM links AS l
	LEFT JOIN recentvotes AS r ON l.id=r.subjectid AND r.type='link'
	LEFT JOIN totalvotes AS t ON l.id=t.subjectid AND t.type='link'
	LEFT JOIN commentcounts AS c ON l.id=c.linkid
	LEFT JOIN users AS u ON l.user=u.id
	$myvotejoin
	WHERE l.id=$id LIMIT 1";
	return(dbFirstResultAssoc($query));
}

function getLinks($page=1,$limit=25,$category=NULL,$user=NULL,$domain=NULL) { // Return array containing array of links, the current page and the total number of pages
	$page = intval($page);
	$limit = intval($limit);
	$user = intval($user);
	$category = dbEscape($category);
	$domain = dbEscape($domain);
	$_SESSION[id] = intval($_SESSION[id]);

	if(!categoryExists($category)) { $where = ""; }
	else { $where = "WHERE category='$category'"; }

	if($domain != NULL) {
		$where = "WHERE domain='$domain'";
	}

	if($user != 0) {
		if($where != "") { 
			$where .= " AND user=$user ";
		}
		else {
			$where = "WHERE user=$user ";
		}
	}

	switch($_GET[order]) {
		case "new": // Latest links
			$order = "time DESC, points DESC";
			break;
		case "top": // Most points (all time)
			$order = "points DESC, time DESC";
			break;
		case "hot": // Most points in the last 48 hours
		default:	
			$order = "votes DESC, points DESC, time DESC";
			break;
	}

	if($_SESSION[id] != 0) { // If we're logged in, include our vote in the query
		$myvote = "IFNULL(v.vote,0) as myvote,";
		$myvotejoin = "LEFT JOIN votes AS v ON l.id=v.subjectid AND v.type='link' AND v.userid=$_SESSION[id]";
	}

	if($page > 0) { $page--; }
	$offset=($page*$limit); // Pagination
	
	$query = "SELECT l.id, l.title, l.link, l.domain, IFNULL(l.category,'main') as category, l.user, u.username AS username,
		  l.time, l.nsfw, IFNULL(r.votes,0) as votes, $myvote IFNULL(t.points,0) as points, c.comments FROM links AS l
		  LEFT JOIN recentvotes AS r ON l.id=r.subjectid AND r.type='link' 
		  LEFT JOIN totalvotes AS t ON l.id=t.subjectid AND t.type='link'
		  LEFT JOIN commentcounts AS c ON l.id=c.linkid
		  LEFT JOIN users AS u ON l.user=u.id
		  $myvotejoin $where
		  ORDER BY $order LIMIT $offset,$limit";
	
	$totalrows = dbRowCount("SELECT * FROM links $where");
	if($totalrows > $limit) { $page = $page+1; }
	else { $page = 0; }
	$totalpages = ceil($totalrows / $limit);

	return(array(dbResultArray($query),"page" => $page, "totalpages" => $totalpages));
}

function linkExists($url) { // Return true if the given URL has already been posted
	$url = dbEscape($url);
	return(dbResultExists("SELECT id FROM links WHERE link='$url'"));
}

function linkIdExists($id) { // return true if the link with the given id exists
	$id = intval($id);
	return(dbResultExists("SELECT id FROM links WHERE id=$id"));
}

function domainExists($domain) { // Return true if there are any links with the given domain
	$domain = dbEscape($domain);
	return(dbResultExists("SELECT id FROM links WHERE domain='$domain'"));
}

function sendLink($input) { // takes an array of sanitized input to insert into the database. On success, returns the id of the submitted link
	$input = dbEscapeArray($input);
	$_SESSION[id] = intval($_SESSION[id]);	
	if(!isset($input[nsfw])) { $input[nsfw] = 0; }
	elseif($input[nsfw] == "on") { $input[nsfw] = 1; }	
		
	// extract hostname from URL
	$url = parse_url($input[url]);
	$domain = $url[host];
	// omit www from the hostname
	if(substr($domain,0,3) == "www") {
		$domain = substr($domain,4);
	}
	
	if(!isset($input[cat])) { $input[cat] = "main"; } // Use main category by default
	if($input[cat] == "") { $input[cat] = "main"; }
	
	// create the category if it doesn't previously exist in the database
	if(!categoryExists($input[cat])) {
		dbQuery("INSERT INTO categories (name) VALUES ('$input[cat]')");
	}	
	
	if(intval($input[edit])) { // Check if we're editing or submitting
		$query = "UPDATE links SET title='$input[title]', link='$input[url]', domain='$domain', 
			  category='$input[cat]', nsfw=$input[nsfw] WHERE id=$input[edit] AND user=$_SESSION[id]";
	}
	
	else {
		$query = "INSERT INTO links (title,link,domain,category,user,nsfw)
			  VALUES ('$input[title]','$input[url]','$domain','$input[cat]',$_SESSION[id],$input[nsfw])";
	}

	$id = dbQueryId($query);
	
	vote($_SESSION[id],$id,'link',1); // Auto-upvote own submissions
	return($id);
}

function deleteLink($linkid) { // Delete link
	$linkid = intval($linkid);
	$_SESSION[id] = intval($_SESSION[id]);	
	return(dbQuery("DELETE FROM links WHERE id=$linkid AND user=$_SESSION[id]"));
}

function nsfw($linkid) { // Toggle nsfw status of link
	$linkid = intval($linkid);
	$nsfw = dbFirstResult("SELECT nsfw FROM links WHERE id=$linkid");
	if($nsfw == 0) { $nsfw = 1; }
	else { $nsfw = 0; }
	dbQuery("UPDATE links SET nsfw=$nsfw WHERE id=$linkid");
}

/* --------- Category table functions --------- */

function categoryExists($cat) { // Return true if the given category exists
	$cat = dbEscape($cat);
	return(dbResultExists("SELECT * FROM categories WHERE name='$cat'"));
}

function getCategories($limit=0) { // Get an array of categories, optionally only those owned by a specific user
	$limit = intval($limit);
	if($limit != 0) { $limit = "LIMIT $limit"; }
	else { $limit = ""; }
	$query = "SELECT c.*,IFNULL(l.count,0) AS count FROM categories AS c LEFT JOIN (
			SELECT category, COUNT(*) AS count FROM links GROUP BY category
		  ) AS l ON l.category=c.name WHERE c.name != 'main' AND count > 0 ORDER BY count DESC $limit";
	return(dbResultArray($query));
}

/* --------- Comment table functions --------- */

function sendComment($input) { // Takes an array of sanitized input. Submits comment to database and returns ID.
	$input = dbEscapeArray($input);
	$linkid = intval($_GET[linkid]);
	$_SESSION[id] = intval($_SESSION[id]);	
	if(intval($input[edit]) == 1) { 
		$owner = dbFirstResult("SELECT userid FROM comments WHERE id=$input[parent]"); 
		
		if($owner == $_SESSION[id]) { // Check that the user owns the comment they are editing
			dbQuery("UPDATE comments SET text='$input[comment]' WHERE id=$input[parent]");
			return($input[parent]);
		}	
		else { return false; }
	}
	
	else {
		if(intval($input[parent] == 0)) { $input[parent] = "NULL"; }
		$queryid = dbQueryId("INSERT INTO comments (userid,linkid,parent,text) VALUES ($_SESSION[id],$linkid,$input[parent],'$input[comment]')");
		vote($_SESSION[id],$queryid,"comment",1); // Auto-upvote own comment
		return($queryid);
	}
}

function getComments($linkid,$user=0,$page=1,$limit=25) { // Returns an array of comments to the given link ID
	$linkid = intval($linkid);
	$user = intval($user);
	$page = intval($page);
	$limit = intval($limit);
	
	$_SESSION[id] = intval($_SESSION[id]);	
	if($linkid != 0) { $where = "WHERE c.linkid=$linkid"; }
		
	elseif($user != 0) { // User page comments listing
		$where = "WHERE c.userid=$user AND c.deleted!=1 "; // No deleted comments!
		$linktitle = ", l.title AS title, l.category"; 		// Include the link
		$linkjoin = "LEFT JOIN links AS l ON l.id=c.linkid";	// title and category
		if($page > 0) { $page--; }
		$offset=($page*$limit); // Pagination doesn't work for our comment tree, so we only use it on the user page
		$qlimit = "LIMIT $offset,$limit";
	}
	
	if($_SESSION[id] != 0) { // Include the logged in user's vote in the query
		$myvote = ", IFNULL(v.vote,0) AS myvote";
		$myvotejoin = "LEFT JOIN votes AS v ON c.id=v.subjectid AND v.type='comment' AND v.userid=$_SESSION[id]";
	}
	
	$query = "SELECT c.*,u.username,IFNULL(t.points,0) AS points $myvote $linktitle
         	  FROM comments AS c 
	          JOIN users AS u ON u.id=c.userid 
	          LEFT JOIN totalvotes AS t ON c.id=t.subjectid AND t.type='comment' $myvotejoin
	          $linkjoin
		  $where ORDER BY time DESC $qlimit";
	
	if($user != 0) {	
		$totalrows = dbRowCount("SELECT * FROM comments AS c $where");
		if($totalrows > $limit) { $page = $page+1; }
		else { $page = 0; }
		$totalpages = ceil($totalrows / $limit);
	}
	else { $page=NULL; $totalpages = NULL; } // No pagination for comment trees
		
	return(array(dbResultArray($query), "page" => $page, "totalpages" => $totalpages));
}

function rawComment($commentid) { // Returns raw form of comment with given ID (for comment editing)
	$commentid = intval($commentid);
	return(dbFirstResult("SELECT text FROM comments WHERE id=$commentid"));
}

function deleteComment($commentid) { // Deletes comment with given ID
	$commentid = intval($commentid);
	return(dbQuery("UPDATE comments SET deleted=1 WHERE id=$commentid AND userid=$_SESSION[id]"));
}

/* --------- Vote table functions --------- */

function getMyPoints($userid) { // Get given user's total points (from their submissions and comments)
	$query="SELECT (IFNULL(IFNULL(l.links,0)+IFNULL(c.comments,0),0)) AS points 
		FROM (
			SELECT users.id AS id, sum(v.vote) as links FROM users 
	        	LEFT JOIN links ON links.user=users.id 
	        	LEFT JOIN votes as v ON v.subjectid=links.id AND v.type='link' GROUP BY id) as l 
	        	JOIN (
				SELECT users.id AS id, sum(v.vote) as comments FROM users 
	        		LEFT JOIN comments ON comments.userid=users.id 
	        		LEFT JOIN votes as v ON v.subjectid=comments.id AND v.type='comment' GROUP BY id
			) as c ON l.id=c.id WHERE c.id=$userid GROUP BY c.id
		"; 
	return(dbFirstResult($query));
}

function vote($userid,$subjectid,$type,$vote) { // Enters, removes or edits a vote from user for subject, returns new vote count.
	$type = dbEscape($type);
	$userid = intval($userid);
	$subjectid = intval($subjectid);
	$vote = intval($vote);

	switch($vote) {
	case 0:
		// Unset/delete vote
		dbQuery("DELETE FROM votes WHERE userid=$userid AND subjectid=$subjectid AND type='$type'");
		break;
	case 1:
	case -1:
		// Check if we've already voted
		$result = dbFirstResult("SELECT * FROM votes WHERE userid=$userid AND subjectid=$subjectid AND type='$type'");
		
		if(empty($result)) { // Make a new vote if we haven't voted before
			dbQuery("INSERT INTO votes (userid,type,subjectid,vote) VALUES($userid,'$type',$subjectid,$vote)");	
		}
	
		elseif($vote != $row[vote]) { // Update our old vote if we have voted before
			dbQuery("UPDATE votes SET vote=$vote WHERE userid=$userid AND subjectid=$subjectid AND type='$type'");
		}
		
		break;
	default: // Any vote other than +1, -1 or 0
		return(false);
		break;
	}

	// Grab and return the new vote count
	$result = dbFirstResult("SELECT points FROM totalvotes WHERE subjectid=$subjectid AND type='$type'");
	if(empty($result)) { $result = 0; }		
	$result .= ($result == 1 || $result == -1) ? ' point' : ' points';
	return("$result");
}
