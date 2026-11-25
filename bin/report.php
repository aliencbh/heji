<?php
// Merge test assertions (JUnit) with Xdebug execution evidence by method, test and site.
require __DIR__.'/../vendor/autoload.php';
$root=dirname(__DIR__); $failed=false; $results=[];
if (is_file("$root/reports/junit.xml")) {
    $suite=simplexml_load_file("$root/reports/junit.xml");
    if (!$suite) exit(1);
    foreach ($suite->xpath('//testcase') as $case) {
        $status='PASS';
        foreach (['error','failure','skipped'] as $type) if (isset($case->$type)) { $status=strtoupper($type); $failed=true; }
        $results[(string)$case['name']]=$status;
    }
}
$inventory=[];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app",FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension()!=='php') continue;
    $path=substr($file->getPathname(),strlen($root)+1); $class=str_replace('/','\\',substr($path,4,-4));
    $reflect=new ReflectionClass('App\\'.$class);
    foreach ($reflect->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName()!==$reflect->getName()) continue;
        $inventory[$reflect->getName().'::'.$method->getName()]=[$path,$method->getStartLine(),$method->getEndLine()];
    }
}
$observed=[];
foreach (file("$root/reports/coverage.jsonl",FILE_IGNORE_NEW_LINES)?:[] as $line) {
    $row=json_decode($line,true,flags:JSON_THROW_ON_ERROR);
    $case=$row['case']; $status=$results[$case]??'INCOMPLETE'; $scope=$row['scope'];
    $file="$root/storage/logs/tests/$scope.log";
    file_put_contents($file,gmdate('c')." $case RESULT $status\n",FILE_APPEND);
    foreach ($inventory as $method=>[$path,$from,$to]) {
        $ran=false;
        foreach ($row['lines'][$path]??[] as $n) if ($n>=$from && $n<=$to) { $ran=true; break; }
        if ($ran) {
            file_put_contents($file,"  $method | $case | $status\n",FILE_APPEND);
            if ($status==='PASS') $observed[$method]=true;
        }
    }
}
foreach ($inventory as $method=>$_) if (!isset($observed[$method])) {
    $failed=true; file_put_contents("$root/storage/logs/tests/common.log","MISSING $method\n",FILE_APPEND); fwrite(STDERR,"Missing coverage: $method\n");
}
if (!$results || !$observed) $failed=true;
exit($failed?1:0);