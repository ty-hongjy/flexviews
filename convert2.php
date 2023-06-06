<?php
require_once('php-sql-parser.php');
require_once('Console/Getopt.php');

function &get_commandline() {

        $cg = new Console_Getopt();
        $args = $cg->readPHPArgv();
        array_shift($args);

        $shortOpts = 'h::v::';
        $longOpts  = array('ini=', 'schema==', 'sql_file==');

        $params = @$cg->getopt2($args, $shortOpts, $longOpts);
        if (PEAR::isError($params)) {
            echo 'Error: ' . $params->getMessage() . "\n";
            exit(1);
        }
        $new_params = array();
        foreach ($params[0] as $param) {
                $param[0] = str_replace('--','', $param[0]);
                $new_params[$param[0]] = $param[1];
        }
        unset($params);

        return $new_params;
}

$params = get_commandline();
$settings = false;
if(empty($params['schema']) || empty($params['sql_file'])) {
	die("usage: add_table.php --schema=<SCHEMA> --sql_file=<SQL FILE>\nWhere SCHEMA is the name of the database and sql_file is the name of the sql file\n");
}

$shcema = $params['schema'];
$sql =file_get_contents($params['sql_file']);
echo $sql;
echo $shcema;


$parser=new PHPSQLParser();
process_sql($sql,$shcema);

function process_parsed($p,$default_db = "") {
	$q = new StdClass();
	$q->mode = 'INCREMENTAL';
	if(!empty($p['GROUP'])) $q->group=true; else $q->group=false;

	$output = "";
	$notes = "";
	#process the select list

	foreach($p['SELECT'] as $s) {
		$expr = "CALL flexviews.add_expr(@mvid,'";
		switch ($s['expr_type']) {
			case 'colref':
				if($q->group) {
					$expr .= "GROUP','";
				} else {
					$expr .= "COLUMN','";
				}	
				$expr .= trim($s['base_expr']) . "','" . trim($s['alias'],'`') . "');\n";
			break;

			#treat aggregate functions special, otherwise it is just like a colref
			case 'expression':
				if($s['sub_tree'][0]['expr_type'] == "aggregate_function") {
					$expr .= $s['sub_tree'][0]['base_expr'] . "','";
					$expr .= trim($s['sub_tree'][1]['base_expr'],"() ") . "','" . trim($s['alias'],'`') . "');\n";
					
				} else {
					if($q->group) {
						$expr .= "GROUP','";
					} else {
						$expr .= "COLUMN','";
					}	
					$expr .= trim($s['base_expr']) . "','" . trim($s['alias'],'`') . "');\n";
				}
			break;

			default:
				echo "UNKNOWN:\n";
				print_r($s);
				exit;
		
		}
		$output .= $expr;
	}

	$output .= "\n";
	$first = true;
	foreach($p['FROM'] as $f) {
		#determine if it is schema.table or just table
		$info = explode('.',$f['table'],2);

		if(count($info) == 1) {
			$db = $default_db;
			$table =$f['table'];
		} else {
			$db = $info[0];
			$table = $info[1];
		}
		if($first) {
			$clause = "NULL";
			$first=0;
		} else {
			if(strtolower($f['ref_type']) == 'using') $f['ref_clause'] = '(' . $f['ref_clause'] . ')';
			$clause = "'" . $f['ref_type'] . ' ' . $f['ref_clause'] . "'";
			// $clause = "'" . $f['ref_type'] . ' ' . @mysql_escape_string($f['ref_clause']) . "'";
		}
		$table = str_replace('.','_',$table);
		$output .= "CALL flexviews.add_table(@mvid,'{$db}','{$table}','{$f['alias']}',{$clause});\n" ;

	}

	$where="";
	if(!empty($p['WHERE'])) foreach($p['WHERE'] as $w) {
		$where .= $w['base_expr'] . ' ';
	}
	$where=trim($where);

	if($where) {
		$where = $where;
		// $where = @mysql_escape_string($where);
		$output .= "CALL flexviews.add_expr(@mvid,'WHERE','{$where}','where_clause');\n";
	}


	unset($p['SELECT']);
	unset($p['INSERT']);
	unset($p['FROM']);
	unset($p['WHERE']);

	return $output;

}


function process_sql($sql, $default_db="", $default_table="", $debug=false) {
	global $parser;

	// echo "process sql1:".$sql;
	$sql = preg_replace(array('/CREATE\s*TABLE\s*/i','/`/'), array('INSERT INTO ',''),$sql);
	// echo "process sql2:".$sql;
	$queries = explode(';', $sql);
	$new_queries = array();
	foreach($queries as $query) {
			$lines = explode("\n", $query);
			$out = "";
			foreach($lines as $line) {
					if(substr(trim($line),0,2) == '--') continue;
					if(substr(trim($line),0,2) == '/*') continue;
					if(substr(trim($line),0,2) == '*/') continue;
					if(substr(trim($line),0,1) == '#') continue;
					if($out) $out .= " ";
					$out .= str_replace("'","''",trim($line));
			}
			$new_queries[] = $out;
	}

        #we are done with the command line parameter.  use $sql for something else
        unset($sql);
		$output = "";
        foreach($new_queries as $sql) {
			if(!trim($sql)) continue;
			$parser->parse($sql);
			$p = $parser->parsed;

			if(!empty($p['INSERT'])) {
				$table=$p['INSERT']['table'];
			} else {
				$table = $default_table;
			}

			$info=explode('.',$table);

			if(count($info) == 1) {
				$db = $default_db;
				$table = $info[0];
			} else {
				$db = $info[0];
				$table = $info[1];
			}

			$output .= "CALL flexviews.create('{$db}', '{$table}', 'INCREMENTAL');\n";
			$output .= "SET @mvid := LAST_INSERT_ID();\n";
			// print_r( $p);
			// echo "\n\n";
			$output .= process_parsed($p,$default_db);
			$output .= "CALL flexviews.enable(@mvid);\n\n";

        }

	echo $output;
}

