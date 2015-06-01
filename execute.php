<?php
/**
 * Simple Heureka.sk crawler
 * Created by: Pavol Husar - phusar@gmail.com
 */
include 'lib/simple_html_dom.php';
include 'lib/PHPExcel.php';
include 'lib/PHPExcel/Writer/Excel2007.php';
include 'HeurekaCrawler.php';
ini_set( "memory_limit", "384M" );

echo 'Script starting at: ' . Date('U') . "\n";

$crawler = new HeurekaCrawler();
$crawler->execute();

echo 'Script done at: ' . Date('U') . "\n";
echo 'Number of processed shops: ' . $crawler->getShopListcount();