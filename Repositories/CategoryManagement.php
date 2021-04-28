<?php
/**
 * Created by mr.vjcspy@gmail.com - khoild@smartosc.com.
 * Date: 15/11/2016
 * Time: 16:56
 */

namespace SM\Category\Repositories;

use SM\Core\Api\Data\LoadCategory;
use SM\Core\Api\Data\XCategory;
use SM\XRetail\Helper\DataConfig;
use SM\XRetail\Repositories\Contract\ServiceAbstract;

/**
 * Class CategoryManagement
 *
 * @package SM\Category\Repositories
 */
class CategoryManagement extends ServiceAbstract
{

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    protected $categoryCollectionFactory;
    /**
     * @var \Magento\Catalog\Model\CategoryFactory
     */
    protected $categoryFactory;
    /**
     * @var \SM\Category\Model\ResourceModel\Catalog\Category\Product
     */
    private $catalogCategoryProduct;

    /**
     * @var \Magento\Store\Model\Store
     */
    private $storeData;
    /**
     * @var \SM\XRetail\Model\OutletFactory
     */
    protected $outletFactory;

    /**
     * @var \Magento\Catalog\Model\Indexer\Category\Flat\State $categoryFlatState
     */
    protected $_categoryFlatConfig;

    /**
     * @var \Magento\Config\Model\Config\Loader
     */
    protected $configLoader;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * CategoryManagement constructor.
     *
     * @param \Magento\Framework\App\RequestInterface                         $requestInterface
     * @param \SM\XRetail\Helper\DataConfig                                   $dataConfig
     * @param \Magento\Store\Model\StoreManagerInterface                      $storeManager
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
     * @param \Magento\Catalog\Model\CategoryFactory                          $categoryFactory
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $requestInterface,
        \SM\XRetail\Helper\DataConfig $dataConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        \Magento\Store\Model\Store $storeData,
        \SM\Category\Model\ResourceModel\Catalog\Category\Product $catalogCategoryProduct,
        \Magento\Catalog\Model\Indexer\Category\Flat\State $categoryFlatState,
        \SM\XRetail\Model\OutletFactory $outletFactory,
        \Magento\Config\Model\Config\Loader $loader,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig               = $scopeConfig;
        $this->storeData                 = $storeData;
        $this->categoryFactory           = $categoryFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->_categoryFlatConfig = $categoryFlatState;
        $this->catalogCategoryProduct    = $catalogCategoryProduct;
        $this->outletFactory             = $outletFactory;
        $this->configLoader              = $loader;
        parent::__construct($requestInterface, $dataConfig, $storeManager);
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCategoryData()
    {
        return $this->loadXCategory($this->getSearchCriteria())->getOutput();
    }

    public function getDataCategory()
    {
        return $this->loadDataCategory($this->getSearchCriteria())->getOutput();
    }

    /**
     * @param null $searchCriteria
     *
     * @return \SM\Core\Api\SearchResult
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Exception
     */
    public function loadXCategory($searchCriteria = null)
    {
        if (is_null($searchCriteria) || !$searchCriteria) {
            $searchCriteria = $this->getSearchCriteria();
        }

        $this->getSearchResult()->setSearchCriteria($searchCriteria);
        $collection = $this->getCategoryCollection($searchCriteria);
        $items      = [];

        if ($collection->getLastPageNumber() < $searchCriteria->getData('currentPage')) {
        } else {
            foreach ($collection as $category) {
                $cat = new XCategory();
                /** @var \Magento\Catalog\Model\Category $category */
                $category->load($category->getEntityId());
                $cat->addData($category->getData());

                $cat->setData('product_ids', $category->getProductCollection()->getAllIds());
                $cat->setData('image_url', $category->getImageUrl());
                $cat->setData('category_child', $this->getActiveChildCategories($category));
                $items[] = $cat;
            }
        }

        return $this->getSearchResult()
                    ->setItems($items)
                    ->setLastPageNumber($collection->getLastPageNumber())
                    ->setTotalCount($collection->getSize());
    }

    /**
     * @param null $searchCriteria
     *
     * @return \SM\Core\Api\SearchResult
     * @throws \Exception
     */
    public function loadDataCategory($searchCriteria = null)
    {
        if (is_null($searchCriteria) || !$searchCriteria) {
            $searchCriteria = $this->getSearchCriteria();
        }

        $this->getSearchResult()->setSearchCriteria($searchCriteria);
        $collection = $this->getCategoryCollection($searchCriteria);
        $items      = [];
        foreach ($collection as $category) {
            $cat = new LoadCategory();
            /** @var \Magento\Catalog\Model\Category $category */
            $category->load($category->getEntityId());
            $cat->setData('entity_id', $category['entity_id']);
            $cat->setData('name', $category['name']);
            $items[] = $cat;
        }

        return $this->getSearchResult()
                    ->setItems($items)
                    ->setLastPageNumber($collection->getLastPageNumber())
                    ->setTotalCount($collection->getSize());
    }


    /**
     * @param \Magento\Framework\DataObject $searchCriteria
     *
     * @return \Magento\Catalog\Model\ResourceModel\Category\Collection
     * @throws \Exception
     */
    public function getCategoryCollection(\Magento\Framework\DataObject $searchCriteria)
    {
        $storeId = $this->getSearchCriteria()->getData('storeId');
        if (is_null($storeId)) {
            throw new \Exception(__('Must have param storeId'));
        } else {
            $this->getStoreManager()->setCurrentStore($storeId);
        }
        $rootCategoryId = $this->getStoreManager()->getStore($storeId)->getRootCategoryId();
        //$rootCategoryId = $this->storeData->getRootCategoryId($storeId);
        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = $this->categoryCollectionFactory->create();

        $collection->addFieldToFilter(
            'path',
            [
                ['like' => '%/' . $rootCategoryId . '/%'],
                ['like' => '%/' . $rootCategoryId],
                ['eq' => $rootCategoryId]
            ]
        );
        $collection->setStoreId($storeId);
        if ($searchCriteria->getData('entity_id') || $searchCriteria->getData('entityId')) {
            if (is_null($searchCriteria->getData('entity_id'))) {
                $ids = $searchCriteria->getData('entityId');
            } else {
                $ids = $searchCriteria->getData('entity_id');
            }
            $collection->addFieldToFilter('entity_id', ['in' => explode(",", $ids)]);
        }

        if(!$searchCriteria->getData('isPWA')){
            $collection->addIsActiveFilter();
        }
        $collection->setCurPage(is_nan($searchCriteria->getData('currentPage')) ? 1 : $searchCriteria->getData('currentPage'));
        if($searchCriteria->getData('isPWA') && !!$searchCriteria->getData('storeId')){
            if ($this->getPWACategoryAttributeStatus($searchCriteria->getData('storeId')) === 'no') {
                $collection->addAttributeToFilter('is_active',1);
            }
            $collection->addAttributeToSort('position')->addAttributeToSort('name');
        }
        if (is_nan($searchCriteria->getData('currentPage'))) {
            $collection->setCurPage(1);
        } else {
            $collection->setCurPage($searchCriteria->getData('currentPage'));
        }
        if (is_nan($searchCriteria->getData('pageSize'))) {
            $collection->setPageSize(
                DataConfig::PAGE_SIZE_LOAD_PRODUCT
            );
        } else {
            $collection->setPageSize(
                $searchCriteria->getData('pageSize')
            );
        }
        $collection->getSelect()->orWhere('e.entity_id = ' . $rootCategoryId);
        return $collection;
    }

    /**
     * @return \Magento\Catalog\Model\Category
     */
    public function getCategoryModel()
    {
        return $this->categoryFactory->create();
    }

    public function getActiveChildCategories($category)
    {
        $children = [];
        if ($this->_categoryFlatConfig->isFlatEnabled() && $category->getUseFlatResource()) {
            $subcategories = (array)$category->getChildrenNodes();
        } else {
            $subcategories = $category->getChildren();
        }
        if($subcategories == ""){
            return [];
        }
        if (is_array($subcategories)){
            return $subcategories;
        } else {
            return explode(",",$subcategories);
        }
    }

    public function getPWACategoryAttributeStatus($storeID) {
        $disableCategory = $this->scopeConfig->getValue('pwa/product_category/pwa_show_disable_categories', 'stores',$storeID);
        return $disableCategory;
    }
}
