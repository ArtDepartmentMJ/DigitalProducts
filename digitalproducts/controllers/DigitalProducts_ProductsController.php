<?php
namespace Craft;

/**
 * Class DigitalProducts_ProductsController
 *
 * @TODO reorder methods properly.
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2016, Pixel & Tonic, Inc.
 */
class DigitalProducts_ProductsController extends BaseController
{
    // TODO permissions

    /**
     * Always check if user is allowed to do commerce-y stuff.
     *
     * @throws HttpException if lacking permissions.
     */
    public function init()
    {
        if (!craft()->userSession->checkPermission('digitalProducts-manageProducts')) {
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }
        parent::init();
    }

    /**
     * Index of digital products
     */
    public function actionIndex(array $variables = [])
    {
        $this->renderTemplate('digitalproducts/products/index', $variables);
    }

    /**
     * Prepare screen to edit a product.
     *
     * @param array $variables
     *
     * @throws HttpException bubbles from DigitalProducts_ProductsController::_prepProductVariables()
     */
    public function actionEdit(array $variables = [])
    {
        $this->_prepProductVariables($variables);

        // Check if the user can edit the products in the product type
        if (!craft()->userSession->getUser()->can('digitalProducts-manageProductType:'.$variables['productType']->id)) {
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }

        if (!empty($variables['product']->id)) {
            $variables['title'] = $variables['product']->title;
        } else {
            $variables['title'] = Craft::t('Create a new product').' - '.$variables['productType']->name;
        }

        $variables['continueEditingUrl'] = "digitalproducts/products/".$variables['productType']->handle."/{id}".
            (craft()->isLocalized() && !empty($variables['localeId']) && craft()->getLanguage() != $variables['localeId'] ? '/'.$variables['localeId'] : '');

        $this->_prepVariables($variables);

        // Enable Live Preview?
        if (!craft()->request->isMobileBrowser(true) && craft()->digitalProducts_productTypes->isProductTypeTemplateValid($variables['productType'])) {
            craft()->templates->includeJs('Craft.LivePreview.init('.JsonHelper::encode([
                    'fields' => '#title-field, #fields > div > div > .field, #sku-field, #price-field',
                    'extraFields' => '#meta-pane .field',
                    'previewUrl' => $variables['product']->getUrl(),
                    'previewAction' => 'digitalProducts/products/previewProduct',
                    'previewParams' => [
                        'typeId' => $variables['productType']->id,
                        'productId' => $variables['product']->id,
                        'locale' => $variables['product']->locale,
                    ]
                ]).');');

            $variables['showPreviewBtn'] = true;

            // Should we show the Share button too?
            if ($variables['product']->id) {
                // If the product is enabled, use its main URL as its share URL.
                if ($variables['product']->getStatus() == DigitalProducts_ProductModel::LIVE) {
                    $variables['shareUrl'] = $variables['product']->getUrl();
                } else {
                    $variables['shareUrl'] = UrlHelper::getActionUrl('digitalProducts/products/shareProduct', [
                        'productId' => $variables['product']->id,
                        'locale' => $variables['product']->locale
                    ]);
                }
            }
        } else {
            $variables['showPreviewBtn'] = false;
        }

        $this->renderTemplate('digitalproducts/products/_edit', $variables);
    }


    /**
     * Prepare product variables from POSt data.
     *
     * @param $variables
     *
     * @throws HttpException in case of missing data or lacking permissions.
     */

    private function _prepProductVariables(&$variables)
    {
        $variables['localeIds'] = craft()->i18n->getEditableLocaleIds();

        if (!$variables['localeIds']) {
            throw new HttpException(403, Craft::t('Your account doesn’t have permission to edit any of this site’s locales.'));
        }

        if (empty($variables['localeId'])) {
            $variables['localeId'] = craft()->language;

            if (!in_array($variables['localeId'], $variables['localeIds'])) {
                $variables['localeId'] = $variables['localeIds'][0];
            }
        } else {
            // Make sure they were requesting a valid locale
            if (!in_array($variables['localeId'], $variables['localeIds'])) {
                throw new HttpException(404);
            }
        }

        if (!empty($variables['productTypeHandle'])) {
            $variables['productType'] = craft()->digitalProducts_productTypes->getProductTypeByHandle($variables['productTypeHandle']);
        }

        if (empty($variables['productType'])) {
            throw new HttpException(404);
        }

        if (empty($variables['product'])) {
            if (!empty($variables['productId'])) {
                $variables['product'] = craft()->digitalProducts_products->getProductById($variables['productId'], $variables['localeId']);

                if (!$variables['product']) {
                    throw new HttpException(404);
                }
            } else {
                $variables['product'] = new DigitalProducts_ProductModel();
                $variables['product']->typeId = $variables['productType']->id;

                if (!empty($variables['localeId'])) {
                    $variables['product']->locale = $variables['localeId'];
                }
            }
        }

        if (!empty($variables['product']->id)) {
            $variables['enabledLocales'] = craft()->elements->getEnabledLocalesForElement($variables['product']->id);
        } else {
            $variables['enabledLocales'] = [];

            foreach (craft()->i18n->getEditableLocaleIds() as $locale) {
                $variables['enabledLocales'][] = $locale;
            }
        }
    }

    /**
     * @param $variables
     *
     * @throws HttpException
     */
    private function _prepVariables(&$variables)
    {
        $variables['tabs'] = [];

        foreach ($variables['productType']->getFieldLayout()->getTabs() as $index => $tab) {
            // Do any of the fields on this tab have errors?
            $hasErrors = false;
            if ($variables['product']->hasErrors()) {
                foreach ($tab->getFields() as $field) {
                    if ($variables['product']->getErrors($field->getField()->handle)) {
                        $hasErrors = true;
                        break;
                    }
                }
            }

            $variables['tabs'][] = [
                'label' => Craft::t($tab->name),
                'url' => '#tab'.($index + 1),
                'class' => ($hasErrors ? 'error' : null)
            ];
        }
    }

    /**
     * Deletes a product.
     *
     * @throws Exception if you try to edit a non existing Id.
     */
    public function actionDeleteProduct()
    {
        $this->requirePostRequest();

        $productId = craft()->request->getRequiredPost('productId');
        $product = craft()->digitalProducts_products->getProductById($productId);

        if (!$product) {
            throw new Exception(Craft::t('No product exists with the ID “{id}”.',
                ['id' => $productId]));
        }

        // Check if the user can edit the products in the product type
        if (!craft()->userSession->getUser()->can('digitalProducts-manageProductType:'.$product->typeId)) {
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }

        if (craft()->digitalProducts_products->deleteProduct($product)) {
            if (craft()->request->isAjaxRequest()) {
                $this->returnJson(['success' => true]);
            } else {
                craft()->userSession->setNotice(Craft::t('Product deleted.'));
                $this->redirectToPostedUrl($product);
            }
        } else {
            if (craft()->request->isAjaxRequest()) {
                $this->returnJson(['success' => false]);
            } else {
                craft()->userSession->setError(Craft::t('Couldn’t delete product.'));

                craft()->urlManager->setRouteVariables([
                    'product' => $product
                ]);
            }
        }
    }

    /**
     * Save a new or existing product.
     */
    public function actionSave()
    {
        $this->requirePostRequest();

        $product = $this->_setProductFromPost();

        // Check if the user can edit the products in the product type
        if (!craft()->userSession->getUser()->can('digitalProducts-manageProductType:'.$product->typeId)) {
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }

        $existingProduct = (bool)$product->id;

        if (craft()->digitalProducts_products->saveProduct($product)) {
            craft()->userSession->setNotice(Craft::t('Product saved.'));
            $this->redirectToPostedUrl($product);
        }

        if (!$existingProduct) {
            $product->id = null;
        }

        craft()->userSession->setError(Craft::t('Couldn’t save product.'));
        craft()->urlManager->setRouteVariables([
            'product' => $product
        ]);
    }

    /**
     * @return Commerce_ProductModel
     * @throws Exception
     */
    private function _setProductFromPost()
    {
        $productId = craft()->request->getPost('productId');
        $locale = craft()->request->getPost('locale');

        if ($productId) {
            $product = craft()->digitalProducts_products->getProductById($productId, $locale);

            if (!$product) {
                throw new Exception(Craft::t('No product with the ID “{id}”',
                    ['id' => $productId]));
            }
        } else {
            $product = new DigitalProducts_ProductModel();
        }

        $data = craft()->request->getPost();

        if (isset($data['typeId'])) {
            $product->typeId = $data['typeId'];
        }

        if (isset($data['enabled'])) {
            $product->enabled = $data['enabled'];
        }

        $product->price = (float)$data['price'];
        $product->sku = $data['sku'];

        $product->postDate = $data['postDate'] ? \Craft\DateTime::createFromString($data['postDate'], \Craft\craft()->timezone) : $product->postDate;
        if (!$product->postDate) {
            $product->postDate = new \Craft\DateTime();
        }
        $product->expiryDate = $data['expiryDate'] ? \Craft\DateTime::createFromString($data['expiryDate'], \Craft\craft()->timezone) : null;

        $product->promotable = $data['promotable'];
        $product->taxCategoryId = $data['taxCategoryId'] ? $data['taxCategoryId'] : $product->taxCategoryId;
        $product->slug = $data['slug'] ? $data['slug'] : $product->slug;

        $product->localeEnabled = (bool)craft()->request->getPost('localeEnabled', $product->localeEnabled);
        $product->getContent()->title = craft()->request->getPost('title', $product->title);
        $product->setContentFromPost('fields');

        return $product;
    }

    /**
     * Previews a product.
     *
     * @throws HttpException
     * @return null
     */
    public function actionPreviewProduct()
    {

        $this->requirePostRequest();

        $product = $this->_setProductFromPost();

        // Check if the user can edit the products in the product type
        if (!craft()->userSession->getUser()->can('digitalProducts-manageProductType:'.$product->typeId)) {
            throw new HttpException(403, Craft::t('This action is not allowed for the current user.'));
        }

        $this->_showProduct($product);
    }

    /**
     * Displays a product.
     *
     * @param DigitalProducts_ProductModel $product
     *
     * @throws HttpException
     * @return null
     */
    private function _showProduct(DigitalProducts_ProductModel $product)
    {
        $productType = $product->getProductType();

        if (!$productType)
        {
            Craft::log('Attempting to preview a product that doesn’t have a type', LogLevel::Error);
            throw new HttpException(404);
        }

        craft()->setLanguage($product->locale);

        // Have this product override any freshly queried products with the same ID/locale
        craft()->elements->setPlaceholderElement($product);

        craft()->templates->getTwig()->disableStrictVariables();

        $this->renderTemplate($productType->template, array(
            'product' => $product
        ));
    }
}
