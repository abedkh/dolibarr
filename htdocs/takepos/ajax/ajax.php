<?php
/* Copyright (C) 2001-2004	Andreu Bisquerra	<jove@bisquerra.com>
 * Copyright (C) 2020		Thibault FOUCART	<support@ptibogxiv.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/takepos/ajax/ajax.php
 *	\brief      Ajax search component for TakePos. It search products of a category.
 */

if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

require '../../main.inc.php'; // Load $user and permissions
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT."/product/class/product.class.php";

$category = GETPOST('category', 'alphanohtml');	// Can be id of category or 'supplements'
$action = GETPOST('action', 'aZ09');
$term = GETPOST('term', 'alpha');
$id = GETPOST('id', 'int');

if (empty($user->rights->takepos->run)) {
	accessforbidden();
}

// Initialize technical object to manage hooks. Note that conf->hooks_modules contains array of hooks
$hookmanager->initHooks(array('takeposproductsearch'));

/*
 * View
 */

if ($action == 'getProducts') {
	$object = new Categorie($db);
	if ($category == "supplements") {
		$category = getDolGlobalInt('TAKEPOS_SUPPLEMENTS_CATEGORY');
	}
	$result = $object->fetch($category);
	if ($result > 0) {
		$prods = $object->getObjectsInCateg("product", 0, 0, 0, getDolGlobalString('TAKEPOS_SORTPRODUCTFIELD'), 'ASC');
		// Removed properties we don't need
		if (is_array($prods) && count($prods) > 0) {
			foreach ($prods as $prod) {
				unset($prod->fields);
				unset($prod->db);
			}
		}
		echo json_encode($prods);
	} else {
		echo 'Failed to load category with id='.$category;
	}
} elseif ($action == 'search' && $term != '') {
	// Change thirdparty with barcode
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	$thirdparty = new Societe($db);
	$result = $thirdparty->fetch('', '', '', $term);

	if ($result && $thirdparty->id > 0) {
		$rows = array();
			$rows[] = array(
				'rowid' => $thirdparty->id,
				'name' => $thirdparty->name,
				'barcode' => $thirdparty->barcode,
		  'object' => 'thirdparty'
			);
			echo json_encode($rows);
			exit;
	}

	// Define $filteroncategids, the filter on category ID if there is a Root category defined.
	$filteroncategids = '';
	if ($conf->global->TAKEPOS_ROOT_CATEGORY_ID > 0) {	// A root category is defined, we must filter on products inside this category tree
		$object = new Categorie($db);
		//$result = $object->fetch($conf->global->TAKEPOS_ROOT_CATEGORY_ID);
		$arrayofcateg = $object->get_full_arbo('product', $conf->global->TAKEPOS_ROOT_CATEGORY_ID, 1);
		if (is_array($arrayofcateg) && count($arrayofcateg) > 0) {
			foreach ($arrayofcateg as $val) {
				$filteroncategids .= ($filteroncategids ? ', ' : '').$val['id'];
			}
		}
	}

	if (!empty($conf->barcode->enabled) && !empty($conf->global->TAKEPOS_BARCODE_RULE_TO_INSERT_PRODUCT)) {
		$barcode_rules = $conf->global->TAKEPOS_BARCODE_RULE_TO_INSERT_PRODUCT;
		$barcode_rules_list = array();

		// get barcode rules
		$barcode_char_nb = 0;
		$barcode_rules_arr = explode('+', $barcode_rules);
		foreach ($barcode_rules_arr as $barcode_rules_values) {
			$barcode_rules_values_arr = explode(':', $barcode_rules_values);
			if (count($barcode_rules_values_arr) == 2) {
				$char_nb = intval($barcode_rules_values_arr[1]);
				$barcode_rules_list[] = array('code' => $barcode_rules_values_arr[0], 'char_nb' => $char_nb);
				$barcode_char_nb += $char_nb;
			}
		}

		$barcode_value_list = array();
		$barcode_offset = 0;
		$barcode_length = dol_strlen($term);
		if ($barcode_length == $barcode_char_nb) {
			$rows = array();

			// split term with barcode rules
			foreach ($barcode_rules_list as $barcode_rule_arr) {
				$code = $barcode_rule_arr['code'];
				$char_nb = $barcode_rule_arr['char_nb'];
				$barcode_value_list[$code] = substr($term, $barcode_offset, $char_nb);
				$barcode_offset += $char_nb;
			}

			if (isset($barcode_value_list['ref'])) {
				//search product from reference
				$sql  = "SELECT rowid, ref, label, tosell, tobuy, barcode, price";
				$sql .= " FROM " . $db->prefix() . "product as p";
				$sql .= " WHERE entity IN (" . getEntity('product') . ")";
				$sql .= " AND ref = '" . $db->escape($barcode_value_list['ref']) . "'";
				if ($filteroncategids) {
					$sql .= " AND EXISTS (SELECT cp.fk_product FROM " . $db->prefix() . "categorie_product as cp WHERE cp.fk_product = p.rowid AND cp.fk_categorie IN (".$db->sanitize($filteroncategids)."))";
				}
				$sql .= " AND tosell = 1";

				$resql = $db->query($sql);
				if ($resql && $db->num_rows($resql) == 1) {
					if ($obj = $db->fetch_object($resql)) {
						$qty = 1;
						if (isset($barcode_value_list['qu'])) {
							$qty_str = $barcode_value_list['qu'];
							if (isset($barcode_value_list['qd'])) {
								$qty_str .= '.' . $barcode_value_list['qd'];
							}
							$qty = floatval($qty_str);
						}

						$ig = '../public/theme/common/nophoto.png';
						if (empty($conf->global->TAKEPOS_HIDE_PRODUCT_IMAGES)) {
							$objProd = new Product($db);
							$objProd->fetch($obj->rowid);
							$image = $objProd->show_photos('product', $conf->product->multidir_output[$objProd->entity], 'small', 1);

							$match = array();
							preg_match('@src="([^"]+)"@', $image, $match);
							$file = array_pop($match);

							if ($file != '') {
								if (!defined('INCLUDE_PHONEPAGE_FROM_PUBLIC_PAGE')) {
									$ig = $file.'&cache=1';
								} else {
									$ig = $file.'&cache=1&publictakepos=1&modulepart=product';
								}
							}
						}

						$rows[] = array(
							'rowid' => $obj->rowid,
							'ref' => $obj->ref,
							'label' => $obj->label,
							'tosell' => $obj->tosell,
							'tobuy' => $obj->tobuy,
							'barcode' => $obj->barcode,
							'price' => $obj->price,
							'object' => 'product',
							'img' => $ig,
							'qty' => $qty,
						);
					}
					$db->free($resql);
				}
			}

			if (count($rows) == 1) {
				echo json_encode($rows);
				exit();
			}
		}
	}

	$sql = 'SELECT rowid, ref, label, tosell, tobuy, barcode, price' ;
	// Add fields from hooks
	$parameters=array();
	$reshook=$hookmanager->executeHooks('printFieldListSelect', $parameters);    // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;

	$sql .= ' FROM '.MAIN_DB_PREFIX.'product as p';

	// Add tables from hooks
	$parameters=array();
	$reshook=$hookmanager->executeHooks('printFieldListTables', $parameters);    // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;

	$sql .= ' WHERE entity IN ('.getEntity('product').')';
	if ($filteroncategids) {
		$sql .= ' AND EXISTS (SELECT cp.fk_product FROM '.MAIN_DB_PREFIX.'categorie_product as cp WHERE cp.fk_product = p.rowid AND cp.fk_categorie IN ('.$db->sanitize($filteroncategids).'))';
	}
	$sql .= ' AND tosell = 1';
	$sql .= natural_search(array('ref', 'label', 'barcode'), $term);
	// Add where from hooks
	$parameters=array();
	$reshook=$hookmanager->executeHooks('printFieldListWhere', $parameters);    // Note that $action and $object may have been modified by hook
	$sql .= $hookmanager->resPrint;

	$resql = $db->query($sql);
	if ($resql) {
		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$objProd = new Product($db);
			$objProd->fetch($obj->rowid);
			$image = $objProd->show_photos('product', $conf->product->multidir_output[$objProd->entity], 'small', 1);

			$match = array();
			preg_match('@src="([^"]+)"@', $image, $match);
			$file = array_pop($match);

			if ($file == "") {
				$ig = '../public/theme/common/nophoto.png';
			} else {
				if (!defined('INCLUDE_PHONEPAGE_FROM_PUBLIC_PAGE')) {
					$ig = $file.'&cache=1';
				} else {
					$ig = $file.'&cache=1&publictakepos=1&modulepart=product';
				}
			}

			$rows[] = array(
				'rowid' => $obj->rowid,
				'ref' => $obj->ref,
				'label' => $obj->label,
				'tosell' => $obj->tosell,
				'tobuy' => $obj->tobuy,
				'barcode' => $obj->barcode,
				'price' => $obj->price,
				'object' => 'product',
				'img' => $ig,
				//'price_formated' => price(price2num($obj->price, 'MU'), 1, $langs, 1, -1, -1, $conf->currency)
			);
		}
		echo json_encode($rows);
	} else {
		echo 'Failed to search product : '.$db->lasterror();
	}
} elseif ($action == "opendrawer" && $term != '') {
	require_once DOL_DOCUMENT_ROOT.'/core/class/dolreceiptprinter.class.php';
	$printer = new dolReceiptPrinter($db);
	// check printer for terminal
	if ($conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term} > 0) {
		$printer->initPrinter($conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term});
		// open cashdrawer
		$printer->pulse();
		$printer->close();
	}
} elseif ($action == "printinvoiceticket" && $term != '' && $id > 0 && !empty($user->rights->facture->lire)) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/dolreceiptprinter.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	$printer = new dolReceiptPrinter($db);
	// check printer for terminal
	if (($conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term} > 0 || $conf->global->TAKEPOS_PRINT_METHOD == "takeposconnector") && $conf->global->{'TAKEPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term} > 0) {
		$object = new Facture($db);
		$object->fetch($id);
		$ret = $printer->sendToPrinter($object, $conf->global->{'TAKEPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term}, $conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term});
	}
} elseif ($action == 'getInvoice') {
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	$object = new Facture($db);
	if ($id > 0) {
		$object->fetch($id);
	}

	echo json_encode($object);
} elseif ($action == 'thecheck') {
	$place = GETPOST('place', 'alpha');
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/class/dolreceiptprinter.class.php';
	$printer = new dolReceiptPrinter($db);
	$printer->sendToPrinter($object, $conf->global->{'TAKEPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term}, $conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term});
}
