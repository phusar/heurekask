<?php
/**
 * Simple Heureka.sk crawler
 * Created by: Pavol Husar - phusar@gmail.com
 */

class HeurekaCrawler
{
    private $shopList = array();

    /**
     * Executes the crawler.
     * @param string $outputFile
     */
    public function execute($url = 'http://obchody.heureka.cz/', $outputFile = 'output-cz.xlsx')
    {
        $this->getCategoryList($url);
        $this->createExcel($outputFile);
    }

    /**
     * Recursive method to get all categories and their content.
     * @param $url
     */
    private function getCategoryList($url)
    {
        // Reset error_get_last for simple_html_dom as many pages on Heureka.sk return error 404
        @trigger_error('reset');

        echo "CRAWLING CATEGORY: $url \n";

        $html = new simple_html_dom();
        @$html->load_file($url);

        if ($html->root == null) {
            // Unable to load the URL. There were some 404 errors on Heureka.sk's categories
            echo "Couldn't load page: $url \n";
            $html->clear();
            return;
        }

        $links = $html->find('ul[class=catlist] li[!class] a');

        if ($url != 'http://obchody.heureka.cz/') {
            $this->grabCategoryContent($url, $html);
        }

        if (!empty($links)) {
            foreach ($links as $element) {
                $html->clear();
                $this->getCategoryList($element->href);
            }
        }
    }

    /**
     * Grabs all shops from a specific category.
     * @param $url
     * @param null $html
     */
    private function grabCategoryContent($url, $html = null)
    {
        if ($html == null) {
            $html = new simple_html_dom();
            @$html->load_file($url);
            $url = explode('?', $url)[0];
        }

        $links = $html->find('div[id=text] div[class=desc] h2 a');
        $eshops = $html->find('div[id=text] div[class=desc] p a');
        foreach ($links as $index => $link) {
            #echo "PARSING SHOP: $link->href ".$eshops[$index]->plaintext." (";
            echo "PARSING SHOP: ".$eshops[$index]->plaintext." (";
            echo $this->getShopListcount();
            echo ")\n";
            $this->addShopToList($link->href, $url, $eshops[$index]->plaintext);
        }

        $link = $html->find('a[class=next]', 0);
        if (!empty($link)) {
            $this->grabCategoryContent($url . substr($link->href, 2), null);
        }
    }

    /**
     * Adds a shop and it's properties to the shop list. Skips the shop if it's already in the list.
     * @param $url
     */
    private function addShopToList($url, $category, $eshop)
    {
        if (!in_array($url, array_keys($this->shopList))) {
            $this->shopList[$url] = $this->getShopDetail($url, $category);
            $this->shopList[$url]['url'] = $eshop;
        } else {
          $newCategory = $this->getShopListValue($this->shopList[$url], 'category') . "$category ";
          $this->shopList[$url]['category'] = $newCategory;
        }
    }

    private function getInnerText($element)
    {
        if ($element != null) {
            return $element->innertext;
        }
        return null;
    }

    /**
     * Grabs the shop's properties.
     * @param $url
     * @return array
     */
    private function getShopDetail($url, $category)
    {
        $html = new simple_html_dom();
        @$html->load_file($url);

        $contentDiv = $html->find('div[id=content]', 0);

        $shopProperties = array();
        $shopProperties['category'] = "$category ";
        $shopProperties['name'] = $this->getInnerText($contentDiv->find('div[class=shop-detail] h1', 0));
        $shopProperties['score'] =
            str_replace('</strong>', '',
                str_replace('<strong>', '',
                    str_replace('<span>', ' ',
                        str_replace('</span>', '', $this->getInnerText($contentDiv->find('div[class=score]', 0)))
                    )
                )
            );

        $badges = array();
        foreach ($contentDiv->find('h6') as $badge) {
            $badges[] = $this->getInnerText($badge);
        }
        $shopProperties['badges'] = $badges;
        $deliveryDetail = $contentDiv->find('ul[class="delivery-detail"]', 0);
        if ($deliveryDetail != null) {
            $shopProperties['deliveryOk'] = $this->getInnerText($deliveryDetail->find('span', 0));
            $shopProperties['deliveryTenDays'] = $this->getInnerText($deliveryDetail->find('span', 1));
            $shopProperties['deliveryDeliveryTime'] =
                str_replace('<small>', ' ',
                    str_replace('</small>', '', $this->getInnerText($deliveryDetail->find('span', 2)))
                );
        }

        $reviews = $this->getInnerText($contentDiv->find('ul[id=menu] li[class=active] a', 0));
        preg_match_all('/\((.*)\)/U', $reviews, $reviewsCount);

        if (!empty($reviewsCount[1])) {
            $shopProperties['reviews'] = $reviewsCount[1][0];
        }


        $html->clear();
        $html->load_file(substr($url, 0, -8) . 'informace/');

        $element = $html->find('div[class=shopInfo] td[class=full] p', 0);
        $shopProperties['description'] = $this->getInnerText($element);

        $tables = $html->find('div[class=shopInfo] table td p');
        for ($i = 0; $i < count($tables); $i++) {
            switch ($tables[$i]->innertext) {
                case 'Provozovatel obchodu:':
                case 'Prevádzkovateľ obchodu:':
                    $shopProperties['owner'] = $this->getInnerText($tables[$i + 1]);
                    break;
                case 'Telefon:':
                case 'Telefón:':
                    $shopProperties['phone'] = $this->getInnerText($tables[$i + 1]);
                    break;
                case 'Telefon pro objednávky:':
                case 'Telefón pre objednávky:':
                    $shopProperties['phoneOrders'] = $this->getInnerText($tables[$i + 1]);
                    break;
                case 'Email:':
                    $shopProperties['email'] = $this->getInnerText($tables[$i + 1]->find('a', 0));
                    break;
                case 'Email pro objednávky:':
                case 'Email na objednávky:':
                    $shopProperties['emailOrders'] = $this->getInnerText($tables[$i + 1]->find('a', 0));
                    break;
                case 'Státy, kam zasíláme:':
                case 'Štáty, kam zasielame:':
                    $shopProperties['shippingTo'] = $this->getInnerText($tables[$i + 1]);
                    break;
            }
        }

        return $shopProperties;
    }

    private function getShopListValue($shop, $key)
    {
        if (array_key_exists($key, $shop)) {
            return $shop[$key];
        }
        return '';
    }

    private function createExcel($outputFile)
    {
        $excel = new PHPExcel();

        $excel->setActiveSheetIndex(0);
        $excel->getActiveSheet()->SetCellValue('A1', 'Název');
        $excel->getActiveSheet()->SetCellValue('B1', 'Hodnocení');
        $excel->getActiveSheet()->SetCellValue('C1', 'Ceny');
        $excel->getActiveSheet()->SetCellValue('D1', 'Dorazilo v pořádku');
        $excel->getActiveSheet()->SetCellValue('E1', 'Dorazilo do 10ti dnů');
        $excel->getActiveSheet()->SetCellValue('F1', 'Průměrná doba dodání');
        $excel->getActiveSheet()->SetCellValue('G1', 'Počet recenzí');
        $excel->getActiveSheet()->SetCellValue('H1', 'Popis');
        $excel->getActiveSheet()->SetCellValue('I1', 'Provozovatel');
        $excel->getActiveSheet()->SetCellValue('J1', 'Telefon');
        $excel->getActiveSheet()->SetCellValue('K1', 'Telefon pro objednávky');
        $excel->getActiveSheet()->SetCellValue('L1', 'Email');
        $excel->getActiveSheet()->SetCellValue('M1', 'Email pro objednávky');
        $excel->getActiveSheet()->SetCellValue('N1', 'Kam zasílají');
        $excel->getActiveSheet()->SetCellValue('O1', 'Kategorie');
        $excel->getActiveSheet()->SetCellValue('P1', 'URL');

        $i = 2;
        foreach ($this->shopList as $shop) {
            $excel->getActiveSheet()->SetCellValue('A' . $i, $shop['name']);
            $excel->getActiveSheet()->SetCellValue('B' . $i, $shop['score']);
            $badges = '';
            foreach ($shop['badges'] as $badge) {
                $badges = $badges . $badge . ', ';
            }
            $excel->getActiveSheet()->SetCellValue('C' . $i, $badges);
            $excel->getActiveSheet()->SetCellValue('D' . $i, $this->getShopListValue($shop, 'deliveryOk'));
            $excel->getActiveSheet()->SetCellValue('E' . $i, $this->getShopListValue($shop, 'deliveryTenDays'));
            $excel->getActiveSheet()->SetCellValue('F' . $i, $this->getShopListValue($shop, 'deliveryDeliveryTime'));
            $excel->getActiveSheet()->SetCellValue('G' . $i, $this->getShopListValue($shop, 'reviews'));
            $excel->getActiveSheet()->SetCellValue('H' . $i, $this->getShopListValue($shop, 'description'));
            $excel->getActiveSheet()->SetCellValue('I' . $i, $this->getShopListValue($shop, 'owner'));
            $excel->getActiveSheet()->SetCellValue('J' . $i, $this->getShopListValue($shop, 'phone'));
            $excel->getActiveSheet()->SetCellValue('K' . $i, $this->getShopListValue($shop, 'phoneOrders'));
            $excel->getActiveSheet()->SetCellValue('L' . $i, $this->getShopListValue($shop, 'email'));
            $excel->getActiveSheet()->SetCellValue('M' . $i, $this->getShopListValue($shop, 'emailOrders'));
            $excel->getActiveSheet()->SetCellValue('N' . $i, $this->getShopListValue($shop, 'shippingTo'));
            $excel->getActiveSheet()->SetCellValue('O' . $i, $this->getShopListValue($shop, 'category'));
            $excel->getActiveSheet()->SetCellValue('P' . $i, $this->getShopListValue($shop, 'url'));
            $i++;
        }

        $writer = new PHPExcel_Writer_Excel2007($excel);
        $writer->save($outputFile);
    }

    /**
     * Gets the number processed shops
     * @return int
     */
    public function getShopListcount()
    {
        return count($this->shopList);
    }
}