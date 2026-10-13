<?php
/**
 * Text-domain check: every translation call must pass the plugin's text domain as a string literal
 * (wp i18n make-pot cannot extract class constants or variables).
 *   php tools/check-text-domains.php [--fix] plugins mu-plugins themes
 * The expected domain is the "Text Domain:" header found in the plugin's folder, or style.css for themes (fallback: folder name).
 * --fix replaces non-literal domain arguments with the literal. Exit 1 when anything is reported.
 */
$fix = false; $roots = [];
foreach (array_slice($argv,1) as $a) { if ($a==='--fix') $fix=true; else $roots[]=$a; }
$pos = ['__'=>1,'_e'=>1,'esc_html__'=>1,'esc_html_e'=>1,'esc_attr__'=>1,'esc_attr_e'=>1,'_x'=>2,'_ex'=>2,'esc_html_x'=>2,'esc_attr_x'=>2,'_n'=>3,'_nx'=>4,'_n_noop'=>2,'_nx_noop'=>3];
$bad=0;
function expected_domain($dir, $folder, $path) {
  static $cache=[];
  $top=substr($path,0,strpos($path,'/'.$folder.'/')+strlen($folder)+1);
  if (isset($cache[$top])) return $cache[$top];
  if (preg_match('~(^|/)themes/~', $top) && is_file($top.'/style.css') && preg_match('/^[ \t\/*#@]*Text Domain:\s*(\S+)/mi', file_get_contents($top.'/style.css', false, null, 0, 8192), $h)) return $cache[$top]=$h[1];
  foreach (glob($top.'/*.php') as $f) {
    if (preg_match('/^[ \t\/*#@]*Text Domain:\s*(\S+)/mi', file_get_contents($f, false, null, 0, 8192), $h)) return $cache[$top]=$h[1];
  }
  return $cache[$top]=$folder;
}
foreach ($roots as $root) {
  if (!is_dir($root)) continue;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    if (substr($f,-4)!=='.php') continue;
    $path=(string)$f;
    if (!preg_match('#(?:plugins|mu-plugins|themes)/([^/]+)/#',$path,$m)) continue;
    $slug=expected_domain(dirname($path,1), $m[1], $path);
    $src=file_get_contents($path); $tk=token_get_all($src); $n=count($tk);
    $edits=[]; // [start,end,replacement] token indexes
    for ($i=0;$i<$n;$i++) {
      if (!is_array($tk[$i]) || $tk[$i][0]!==T_STRING || !isset($pos[$tk[$i][1]])) continue;
      // previous significant token must not be -> :: function
      $prev=$i-1; while($prev>=0 && is_array($tk[$prev]) && $tk[$prev][0]===T_WHITESPACE) $prev--;
      if ($prev>=0 && is_array($tk[$prev]) && in_array($tk[$prev][0],[T_OBJECT_OPERATOR,T_DOUBLE_COLON,T_FUNCTION],true)) continue;
      $j=$i+1; while($j<$n && is_array($tk[$j]) && $tk[$j][0]===T_WHITESPACE) $j++;
      if ($j>=$n || $tk[$j]!=='(') continue;
      // split args
      $depth=0; $arg=0; $args=[[ ]]; $start=$j+1;
      for ($k=$j;$k<$n;$k++) {
        $tok=$tk[$k]; $s=is_array($tok)?$tok[1]:$tok;
        if (in_array($s,['(','[','{'],true) || (is_array($tok)&&in_array($tok[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))) { $depth++; if($depth===1) continue; }
        if (in_array($s,[')',']','}'],true)) { $depth--; if($depth===0) break; }
        if ($depth===1 && $s===',') { $arg++; $args[$arg]=[]; continue; }
        $args[$arg][]=$k;
      }
      $want=$pos[$tk[$i][1]];
      if (!isset($args[$want])) continue;
      $idx=array_values(array_filter($args[$want],fn($k)=>!(is_array($tk[$k])&&in_array($tk[$k][0],[T_WHITESPACE,T_COMMENT],true))));
      $line=$tk[$i][2];
      if (count($idx)===1 && is_array($tk[$idx[0]]) && $tk[$idx[0]][0]===T_CONSTANT_ENCAPSED_STRING) {
        $lit=trim($tk[$idx[0]][1],"'\"");
        if ($lit!==$slug) { echo "WRONG $path:$line '$lit'\n"; $bad++; }
        continue;
      }
      $txt=implode('',array_map(fn($k)=>is_array($tk[$k])?$tk[$k][1]:$tk[$k],$idx));
      echo "NONLIT $path:$line $txt\n"; $bad++;
      if ($idx) $edits[]=[$idx[0],end($idx),"'$slug'"];
    }
    if ($fix && $edits) {
      usort($edits,fn($a,$b)=>$b[0]<=>$a[0]);
      foreach ($edits as [$a,$b,$r]) { for($k=$a;$k<=$b;$k++){ if(is_array($tk[$k])) $tk[$k][1]=''; else $tk[$k]=''; } if(is_array($tk[$a])) $tk[$a][1]=$r; else $tk[$a]=$r; }
      file_put_contents($path, implode('',array_map(fn($x)=>is_array($x)?$x[1]:$x,$tk)));
    }
  }
}
echo "total=$bad\n";
exit($bad?1:0);
