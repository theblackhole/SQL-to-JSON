<?php
/*
Copyright (c) 2016 Cyriaque DELAUNAY
Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

header('HTTP/1.1 200 OK');	// prevent 404
header('Content-Type: application/json');
session_start();


				// BEGIN DB CONFIGURATION \\

					$PARAM_host='localhost';
					$PARAM_port='3306';
					$PARAM_db_name='yourdatabase';
					$PARAM_user='username';
					$PARAM_pass='password';

				// END DB CONFIGURATION \\
try
{
	$db = new PDO('mysql:host='.$PARAM_host.';port='.$PARAM_port.';dbname='.$PARAM_db_name, $PARAM_user, $PARAM_pass);
}
catch(Exception $e)
{
    echo 'Error : '.$e->getMessage().'<br />';
    echo 'N° : '.$e->getCode();
	die();
}

$urlq = array();

$filter = json_decode(file_get_contents("models.json"));		// filter the query using the json

if($_SERVER['REQUEST_METHOD'] == "POST")					// if the query is a post
{
	$postTarget = htmlspecialchars($_GET['table']);
	$f = "post/".$postTarget.".php";
	include($f);
	exit();
}

$urlq["where"] = [];

foreach ($_GET as $key => $value)
{
	$urlq[htmlspecialchars($key)] = htmlspecialchars($value);
}

if(!isset($urlq['table']))
{
	$return['success'] = false;
	$return['message'] = "MISSING_REQUIRED_INPUT_TABLE";
	print(json_encode($return));
	exit;
}

$singleValue = true;		//here
$qTable = $urlq['table'];
$qId;
$queryTable = $filter->$urlq['table']->table;
$queryTarget = "";
$queryLimit = 10;
$queryOrder = "id";

$return = array();
$return['success'] = false;
$return['count'] = 0;

if(!empty($urlq['id']))		//set the query id
{
	$qId = intval($urlq['id']);
	$singleValue = false;
	$queryTarget = $qId;
	$queryWhere = "WHERE id = ".$queryTarget;
}
elseif(!empty($urlq['where']))
{
	$wheres = $p = explode(":", $urlq['where']);
	$queryWhere = "WHERE ";
	$qws = "";

	foreach ($wheres as $value)
	{
		$p = explode("=", $value);
		if(isset($p[1]) && isset($p[0]))
		{
			$queryWhere = $queryWhere.$qws.$p[0].' = "'.$p[1].'"';
			$qws = " AND ";
		}
	}
}
else
{
	$queryWhere = "";
}


							// exeption : if the query is /me, show the current logged user info
if($urlq['table'] == "me")
{
	if(isset($_SESSION['id']))
	{
		$queryWhere = "WHERE id = ".$_SESSION['id'];
	}
	else
	{
		$queryWhere = "WHERE id = 0";
	}
}

if(!empty($_GET['limit']))		// set a limit
{
	$limit = intval($_GET['limit']);
	$queryLimit = $limit;
}

if(!empty($_GET['order']))		// set a limit
{
	$order = htmlspecialchars($_GET['order']);
	$queryOrder = $order;
}

$queryFilter = generateFilter($qTable);

$q = generateQuery($queryFilter,$queryTable,$queryWhere,$queryOrder,$queryLimit);

$b = $db->query($q);

if(!$b)						// in case of invalid table : stop
{
	$return['success'] = false;
	$return['message'] = $db->errorInfo();
	$return['table'] = $queryTable;
	print(json_encode($return));
	exit;
}

$return['success'] = true;
$return[$qTable] = array();

while($r = $b->fetch(PDO::FETCH_ASSOC))
{
	$return['count']++;

	foreach ($r as $key => $value)
	{
		$dk = explode("_", $key);
		if(count($dk > 1) && $dk[0] != "id")
		{
			$lastk = $dk[count($dk) -1];	//search if its another table reference (ranks, users...)
			if($lastk == "id")
			{
				$kname = str_replace("_".$lastk, "", $key);	// name of the key in the result
				$tname = $kname."s";
				
				// launch another query

				$queryFilter = generateFilter($tname);

				$y = $db->query('SELECT '.$queryFilter.' from '.$tname.' where id = '.$value.' limit 1');
				if($y)
				{
					$u = $y->fetch(PDO::FETCH_ASSOC);
					$r[$kname] = $u;
					unset($r[$key]);
				}
				
			}
		}
	}

		// ADDING THE JOINED TABLES
	$queryFilter = "";
	$queryTableSing = substr($queryTable, 0, -1);		
	if(property_exists ($filter,$qTable))
	{
		if(property_exists ($filter->$qTable,'join'))
		{
			$f = $filter->$qTable->join;
			$c = 0;
			foreach ($f as $value)									// generate the filter inside the query
			{
				$joinTableNameSing = substr($value, 0, -1);		// subtitles -> subtitle used for model.json
				$queryWhere = "WHERE ".$queryTableSing."_id = ".$r['id'];
				$queryFilter = generateFilter($joinTableNameSing);
				$q = generateQuery($queryFilter,$value,$queryWhere,"","");
				$c = $db->query($q);
				if($c)
				{
					$r[$value] = array();
					while($t = $c->fetch(PDO::FETCH_ASSOC))
					{
						array_push($r[$value], $t);
					}
				}
				
			}
		}
	}

	array_push($return[$qTable],$r);
	$return['success'] = true;

		
}


if($return['count'] == 0)
{
	$return['success'] = true;
	$return['message'] = "NO_RESULTS";
}

function generateFilter($qTable)				// get only the autorized param for a query
{
	$queryFilter = "";

	$filter = json_decode(file_get_contents("models.json"));		// filter the query using the json
	if(property_exists ($filter,$qTable))
	{
		$f = $filter->$qTable->rows;
		$c = 0;
		foreach ($f as $value)									// generate the filter inside the query
		{
			$v = "";
			if($c > 0)
			{
				$v = ", ";
			}

			$queryFilter = $queryFilter.$v.$value;
			$c++;
		}
	}

	return $queryFilter;
}

function generateQuery($queryFilter,$queryTable,$queryWhere,$queryOrder,$queryLimit)
{
	if(empty($queryOrder))
	{
		$queryOrder = "id";
	}
	if(empty($queryLimit))
	{
		$queryLimit = "9999";
	}

	$r = "SELECT ".$queryFilter." FROM ".$queryTable." ".$queryWhere." ORDER BY ".$queryOrder." LIMIT ".$queryLimit;
	return $r;
}

print json_encode($return);
