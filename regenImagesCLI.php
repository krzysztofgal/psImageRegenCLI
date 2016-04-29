#!/usr/bin/env php
<?php

if(php_sapi_name() !== 'cli' OR !defined('STDIN')) {
    exit;
}

define('_PS_ADMIN_DIR_', getcwd());
require_once(__DIR__ . '/config/config.inc.php');
require_once(__DIR__ . '/config/defines.inc.php');

$deleteAll = true;
echo "Detete all images? (Y/N) - ";

$stdin = fopen('php://stdin', 'r');
$response = fgetc($stdin);
if ($response != 'Y') {
    $deleteAll = false;
    echo "skipping delete\n";
}

_regenerateThumbnails('all',$deleteAll);

function _regenerateThumbnails($type = 'all', $deleteOldImages = false)
{
    $errors = [];
    $languages = Language::getLanguages(false);

    $process = array(
        array('type' => 'categories', 'dir' => _PS_CAT_IMG_DIR_),
        array('type' => 'manufacturers', 'dir' => _PS_MANU_IMG_DIR_),
        array('type' => 'suppliers', 'dir' => _PS_SUPP_IMG_DIR_),
        array('type' => 'scenes', 'dir' => _PS_SCENE_IMG_DIR_),
        array('type' => 'products', 'dir' => _PS_PROD_IMG_DIR_),
        array('type' => 'stores', 'dir' => _PS_STORE_IMG_DIR_)
    );

    // Launching generation process
    foreach ($process as $proc) {
        if ($type != 'all' && $type != $proc['type']) {
            continue;
        }

        // Getting format generation
        $formats = ImageType::getImagesTypes($proc['type']);
        if ($type != 'all') {
            $format = strval(Tools::getValue('format_'.$type));
            if ($format != 'all') {
                foreach ($formats as $k => $form) {
                    if ($form['id_image_type'] != $format) {
                        unset($formats[$k]);
                    }
                }
            }
        }

        if ($deleteOldImages) {
            echo "Old images will be deleted.\n";
            _deleteOldImages($proc['dir'], $formats, ($proc['type'] == 'products' ? true : false));
        }
        if (($return = _regenerateNewImages($proc['dir'], $formats, ($proc['type'] == 'products' ? true : false))) === true) {
            if (!count($errors)) {
                $errors[] = sprintf(displayError('Cannot write images for this type: %s. Please check the %s folder\'s writing permissions.'), $proc['type'], $proc['dir']);
            }
        } elseif ($return == 'timeout') {
            $errors[] = displayError('Only part of the images have been regenerated. The server timed out before finishing.');
        } else {
            if ($proc['type'] == 'products') {
                if (_regenerateWatermark($proc['dir'], $formats) == 'timeout') {
                    $errors[] = displayError('Server timed out. The watermark may not have been applied to all images.');
                }
            }
            if (!count($errors)) {
                if (_regenerateNoPictureImages($proc['dir'], $formats, $languages)) {
                    $errors[] = sprintf(displayError('Cannot write "No picture" image to (%s) images folder. Please check the folder\'s writing permissions.'), $proc['type']);
                }
            }
        }
    }
    return (count($errors) > 0 ? false : true);
}

function displayError($string = 'Fatal error', $htmlentities = true, Context $context = null)
{
    global $_ERRORS;

    if (is_null($context)) {
        $context = Context::getContext();
    }

    @include_once(_PS_TRANSLATIONS_DIR_.$context->language->iso_code.'/errors.php');

    if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ && $string == 'Fatal error') {
        return ('<pre>'.print_r(debug_backtrace(), true).'</pre>');
    }
    if (!is_array($_ERRORS)) {
        return $htmlentities ? Tools::htmlentitiesUTF8($string) : $string;
    }
    $key = md5(str_replace('\'', '\\\'', $string));
    $str = (isset($_ERRORS) && is_array($_ERRORS) && array_key_exists($key, $_ERRORS)) ? $_ERRORS[$key] : $string;
    $return = $htmlentities ? Tools::htmlentitiesUTF8(stripslashes($str)) : $str;
    echo $return."\n";
    return $return;
}

function _regenerateNewImages($dir, $type, $productsImages = false)
{
    if (!is_dir($dir)) {
        return false;
    }

    $errors = [];

    $generate_hight_dpi_images = (bool)Configuration::get('PS_HIGHT_DPI');

    if (!$productsImages) {
        $formated_thumb_scene = ImageType::getFormatedName('thumb_scene');
        $formated_medium = ImageType::getFormatedName('medium');
        foreach (scandir($dir) as $image) {
            if (preg_match('/^[0-9]*\.jpg$/', $image)) {
                foreach ($type as $k => $imageType) {
                    // Customizable writing dir
                    $newDir = $dir;
                    if ($imageType['name'] == $formated_thumb_scene) {
                        $newDir .= 'thumbs/';
                    }
                    if (!file_exists($newDir)) {
                        continue;
                    }

                    if (($dir == _PS_CAT_IMG_DIR_) && ($imageType['name'] == $formated_medium) && is_file(_PS_CAT_IMG_DIR_.str_replace('.', '_thumb.', $image))) {
                        $image = str_replace('.', '_thumb.', $image);
                    }

                    if (!file_exists($newDir.substr($image, 0, -4).'-'.stripslashes($imageType['name']).'.jpg')) {
                        if (!file_exists($dir.$image) || !filesize($dir.$image)) {
                            $errors[] = sprintf(displayError('Source file does not exist or is empty (%s)'), $dir.$image);
                        } elseif (!ImageManager::resize($dir.$image, $newDir.substr(str_replace('_thumb.', '.', $image), 0, -4).'-'.stripslashes($imageType['name']).'.jpg', (int)$imageType['width'], (int)$imageType['height'])) {
                            $errors[] = sprintf(displayError('Failed to resize image file (%s)'), $dir.$image);
                        }

                        if ($generate_hight_dpi_images) {
                            if (!ImageManager::resize($dir.$image, $newDir.substr($image, 0, -4).'-'.stripslashes($imageType['name']).'2x.jpg', (int)$imageType['width']*2, (int)$imageType['height']*2)) {
                                $errors[] = sprintf(displayError('Failed to resize image file to high resolution (%s)'), $dir.$image);
                            }
                        }
                    }
                }
                if(empty($errors)) {
                    echo '_regenerateNewImages(!PI):'.$image."\n";
                }
            }
        }
    } else {
        foreach (Image::getAllImages() as $image) {
            $imageObj = new Image($image['id_image']);
            $existing_img = $dir.$imageObj->getExistingImgPath().'.jpg';
            if (file_exists($existing_img) && filesize($existing_img)) {
                foreach ($type as $imageType) {
                    if (!file_exists($dir.$imageObj->getExistingImgPath().'-'.stripslashes($imageType['name']).'.jpg')) {
                        if (!ImageManager::resize($existing_img, $dir.$imageObj->getExistingImgPath().'-'.stripslashes($imageType['name']).'.jpg', (int)$imageType['width'], (int)$imageType['height'])) {
                            $errors[] = sprintf(displayError('Original image is corrupt (%s) for product ID %2$d or bad permission on folder'), $existing_img, (int)$imageObj->id_product);
                        }

                        if ($generate_hight_dpi_images) {
                            if (!ImageManager::resize($existing_img, $dir.$imageObj->getExistingImgPath().'-'.stripslashes($imageType['name']).'2x.jpg', (int)$imageType['width']*2, (int)$imageType['height']*2)) {
                                $errors[] = sprintf(displayError('Original image is corrupt (%s) for product ID %2$d or bad permission on folder'), $existing_img, (int)$imageObj->id_product);
                            }
                        }
                    }
                }
                if(empty($errors)) {
                    echo '_regenerateNewImages(PI): '.$existing_img."\n";
                }
            } else {
                $errors[] = sprintf(displayError('Original image is missing or empty (%1$s) for product ID %2$d'), $existing_img, (int)$imageObj->id_product);
            }
        }
    }

    return (bool)count($errors);
}

function _deleteOldImages($dir, $type, $product = false)
{
    if (!is_dir($dir)) {
        return false;
    }
    $toDel = scandir($dir);

    foreach ($toDel as $d) {
        foreach ($type as $imageType) {
            if (preg_match('/^[0-9]+\-'.($product ? '[0-9]+\-' : '').$imageType['name'].'\.jpg$/', $d)
                || (count($type) > 1 && preg_match('/^[0-9]+\-[_a-zA-Z0-9-]*\.jpg$/', $d))
                || preg_match('/^([[:lower:]]{2})\-default\-'.$imageType['name'].'\.jpg$/', $d)) {
                if (file_exists($dir.$d)) {
                    unlink($dir.$d);
                }
            }
        }
    }

    // delete product images using new filesystem.
    if ($product) {
        $productsImages = Image::getAllImages();
        foreach ($productsImages as $image) {
            $imageObj = new Image($image['id_image']);
            $imageObj->id_product = $image['id_product'];
            if (file_exists($dir.$imageObj->getImgFolder())) {
                $toDel = scandir($dir.$imageObj->getImgFolder());
                foreach ($toDel as $d) {
                    foreach ($type as $imageType) {
                        if (preg_match('/^[0-9]+\-'.$imageType['name'].'\.jpg$/', $d) || (count($type) > 1 && preg_match('/^[0-9]+\-[_a-zA-Z0-9-]*\.jpg$/', $d))) {
                            if (file_exists($dir.$imageObj->getImgFolder().$d)) {
                                unlink($dir.$imageObj->getImgFolder().$d);
                            }
                        }
                    }
                }
            }
        }
    }
}

function _regenerateNoPictureImages($dir, $type, $languages)
{
    $errors = false;
    $generate_hight_dpi_images = (bool)Configuration::get('PS_HIGHT_DPI');

    foreach ($type as $image_type) {
        foreach ($languages as $language) {
            $file = $dir.$language['iso_code'].'.jpg';
            if (!file_exists($file)) {
                $file = _PS_PROD_IMG_DIR_.Language::getIsoById((int)Configuration::get('PS_LANG_DEFAULT')).'.jpg';
            }
            if (!file_exists($dir.$language['iso_code'].'-default-'.stripslashes($image_type['name']).'.jpg')) {
                if (!ImageManager::resize($file, $dir.$language['iso_code'].'-default-'.stripslashes($image_type['name']).'.jpg', (int)$image_type['width'], (int)$image_type['height'])) {
                    $errors = true;
                }

                if ($generate_hight_dpi_images) {
                    if (!ImageManager::resize($file, $dir.$language['iso_code'].'-default-'.stripslashes($image_type['name']).'2x.jpg', (int)$image_type['width']*2, (int)$image_type['height']*2)) {
                        $errors = true;
                    }
                }
                if(!$errors) {
                    echo '_regenerateNoPictureImages: '.$file."\n";
                }
            }
        }
    }
    return $errors;
}

function _regenerateWatermark($dir, $type = null)
{
    $result = Db::getInstance()->executeS('
		SELECT m.`name` FROM `'._DB_PREFIX_.'module` m
		LEFT JOIN `'._DB_PREFIX_.'hook_module` hm ON hm.`id_module` = m.`id_module`
		LEFT JOIN `'._DB_PREFIX_.'hook` h ON hm.`id_hook` = h.`id_hook`
		WHERE h.`name` = \'actionWatermark\' AND m.`active` = 1');

    if ($result && count($result)) {
        $productsImages = Image::getAllImages();
        foreach ($productsImages as $image) {
            $imageObj = new Image($image['id_image']);
            $file = $dir.$imageObj->getExistingImgPath().'.jpg';
            if (file_exists($file)) {
                foreach ($result as $module) {
                    $moduleInstance = Module::getInstanceByName($module['name']);
                    if ($moduleInstance && is_callable(array($moduleInstance, 'hookActionWatermark'))) {
                        @call_user_func(array($moduleInstance, 'hookActionWatermark'), array('id_image' => $imageObj->id, 'id_product' => $imageObj->id_product, 'image_type' => $type));
                        echo '_regenerateWatermark: '.$file."\n";
                    }
                }
            }
        }
    }
}

?>