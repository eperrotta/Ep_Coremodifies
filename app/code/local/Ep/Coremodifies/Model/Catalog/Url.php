<?php

/**
 * Class Ep_Coremodifies_Model_Catalog_Url
 * Overrides Mage_Catalog_Model_Url as in the patch SUPEE-389
 * https://gist.github.com/piotrekkaminski/c348538ca91ba35773be
 */
class Ep_Coremodifies_Model_Catalog_Url extends Mage_Catalog_Model_Url
{

	/**
	 * Get requestPath that was not used yet.
	 *
	 * Will try to get unique path by adding -1 -2 etc. between url_key and optional url_suffix
	 *
	 * @param int $storeId
	 * @param string $requestPath
	 * @param string $idPath
	 * @return string
	 */
	public function getUnusedPath($storeId, $requestPath, $idPath)
	{
		$urlKey = '';
		return $this->getUnusedPathByUrlkey($storeId, $requestPath, $idPath, $urlKey);
	}

	/**
	 * Get requestPath that was not used yet.
	 *
	 * Will try to get unique path by adding -1 -2 etc. between url_key and optional url_suffix
	 *
	 * @param int $storeId
	 * @param string $requestPath
	 * @param string $idPath
	 * @param string $urlKey
	 * @return string
	 */
	public function getUnusedPathByUrlkey($storeId, $requestPath, $idPath, $urlKey = '')
	{
		if (strpos($idPath, 'product') !== false) {
			$suffix = $this->getProductUrlSuffix($storeId);
		} else {
			$suffix = $this->getCategoryUrlSuffix($storeId);
		}
		if (empty($requestPath)) {
			$requestPath = '-';
		} elseif ($requestPath == $suffix) {
			$requestPath = '-' . $suffix;
		}

		/**
		 * Validate maximum length of request path
		 */
		if (strlen($requestPath) > self::MAX_REQUEST_PATH_LENGTH + self::ALLOWED_REQUEST_PATH_OVERFLOW) {
			$requestPath = substr($requestPath, 0, self::MAX_REQUEST_PATH_LENGTH);
		}

		if (isset($this->_rewrites[$idPath])) {
			$this->_rewrite = $this->_rewrites[$idPath];
			if ($this->_rewrites[$idPath]->getRequestPath() == $requestPath) {
				return $requestPath;
			}
		} else {
			$this->_rewrite = null;
		}

		$rewrite = $this->getResource()->getRewriteByRequestPath($requestPath, $storeId);
		if ($rewrite && $rewrite->getId()) {
			if ($rewrite->getIdPath() == $idPath) {
				$this->_rewrite = $rewrite;
				return $requestPath;
			}
			// match request_url abcdef1234(-12)(.html) pattern
			$match = array();
			$regularExpression = '#(?P<prefix>(.*/)?' . preg_quote($urlKey) . ')(-(?P<increment>[0-9]+))?(?P<suffix>'
				. preg_quote($suffix) . ')?$#i';
			if (!preg_match($regularExpression, $requestPath, $match)) {
				return $this->getUnusedPathByUrlkey($storeId, '-', $idPath, $urlKey);
			}
			$match['prefix'] = $match['prefix'] . '-';
			$match['suffix'] = isset($match['suffix']) ? $match['suffix'] : '';

			$lastRequestPath = $this->getResource()
				->getLastUsedRewriteRequestIncrement($match['prefix'], $match['suffix'], $storeId);
			if ($lastRequestPath) {
				$match['increment'] = $lastRequestPath;
			}
			return $match['prefix']
			. (isset($match['increment']) ? ($match['increment'] + 1) : '1')
			. $match['suffix'];
		} else {
			return $requestPath;
		}
	}

	/**
	 * Get unique category request path
	 *
	 * @param Varien_Object $category
	 * @param string $parentPath
	 * @return string
	 */
	public function getCategoryRequestPath($category, $parentPath)
	{
		$storeId = $category->getStoreId();
		$idPath = $this->generatePath('id', null, $category);
		$categoryUrlSuffix = $this->getCategoryUrlSuffix($storeId);

		if (isset($this->_rewrites[$idPath])) {
			$this->_rewrite = $this->_rewrites[$idPath];
			$existingRequestPath = $this->_rewrites[$idPath]->getRequestPath();
		}

		if ($category->getUrlKey() == '') {
			$urlKey = $this->getCategoryModel()->formatUrlKey($category->getName());
		} else {
			$urlKey = $this->getCategoryModel()->formatUrlKey($category->getUrlKey());
		}

		if (null === $parentPath) {
			$parentPath = $this->getResource()->getCategoryParentPath($category);
		} elseif ($parentPath == '/') {
			$parentPath = '';
		}
		$parentPath = Mage::helper('catalog/category')->getCategoryUrlPath($parentPath, true, $storeId);

		$requestPath = $parentPath . $urlKey;
		$regexp = '/^' . preg_quote($requestPath, '/') . '(\-[0-9]+)?' . preg_quote($categoryUrlSuffix, '/') . '$/i';
		if (isset($existingRequestPath) && preg_match($regexp, $existingRequestPath)) {
			return $existingRequestPath;
		}

		$fullPath = $requestPath . $categoryUrlSuffix;
		if ($this->_deleteOldTargetPath($fullPath, $idPath, $storeId)) {
			return $requestPath;
		}

		return $this->getUnusedPathByUrlkey($storeId, $fullPath,
			$this->generatePath('id', null, $category), $urlKey
		);
	}

	/**
	 * Get unique product request path
	 *
	 * @param   Varien_Object $product
	 * @param   Varien_Object $category
	 * @return  string
	 */
	public function getProductRequestPath($product, $category)
	{
		if ($product->getUrlKey() == '') {
			$urlKey = $this->getProductModel()->formatUrlKey($product->getName());
		} else {
			$urlKey = $this->getProductModel()->formatUrlKey($product->getUrlKey());
		}
		$storeId = $category->getStoreId();
		$suffix = $this->getProductUrlSuffix($storeId);
		$idPath = $this->generatePath('id', $product, $category);
		/**
		 * Prepare product base request path
		 */
		if ($category->getLevel() > 1) {
			// To ensure, that category has path either from attribute or generated now
			$this->_addCategoryUrlPath($category);
			$categoryUrl = Mage::helper('catalog/category')->getCategoryUrlPath($category->getUrlPath(),
				false, $storeId);
			$requestPath = $categoryUrl . '/' . $urlKey;
		} else {
			$requestPath = $urlKey;
		}

		if (strlen($requestPath) > self::MAX_REQUEST_PATH_LENGTH + self::ALLOWED_REQUEST_PATH_OVERFLOW) {
			$requestPath = substr($requestPath, 0, self::MAX_REQUEST_PATH_LENGTH);
		}

		$this->_rewrite = null;
		/**
		 * Check $requestPath should be unique
		 */
		if (isset($this->_rewrites[$idPath])) {
			$this->_rewrite = $this->_rewrites[$idPath];
			$existingRequestPath = $this->_rewrites[$idPath]->getRequestPath();

			$regexp = '/^' . preg_quote($requestPath, '/') . '(\-[0-9]+)?' . preg_quote($suffix, '/') . '$/i';
			if (preg_match($regexp, $existingRequestPath)) {
				return $existingRequestPath;
			}

			$existingRequestPath = preg_replace('/' . preg_quote($suffix, '/') . '$/', '', $existingRequestPath);
			/**
			 * Check if existing request past can be used
			 */
			if ($product->getUrlKey() == '' && !empty($requestPath)
				&& strpos($existingRequestPath, $requestPath) === 0
			) {
				$existingRequestPath = preg_replace(
					'/^' . preg_quote($requestPath, '/') . '/', '', $existingRequestPath
				);
				if (preg_match('#^-([0-9]+)$#i', $existingRequestPath)) {
					return $this->_rewrites[$idPath]->getRequestPath();
				}
			}

			$fullPath = $requestPath . $suffix;
			if ($this->_deleteOldTargetPath($fullPath, $idPath, $storeId)) {
				return $fullPath;
			}
		}
		/**
		 * Check 2 variants: $requestPath and $requestPath . '-' . $productId
		 */
		$validatedPath = $this->getResource()->checkRequestPaths(
			array($requestPath . $suffix, $requestPath . '-' . $product->getId() . $suffix),
			$storeId
		);

		if ($validatedPath) {
			return $validatedPath;
		}
		/**
		 * Use unique path generator
		 */
		return $this->getUnusedPathByUrlkey($storeId, $requestPath . $suffix, $idPath, $urlKey);
	}

	public function generatePath($type = 'target', $product = null, $category = null, $parentPath = null)
	{
		if (!$product && !$category) {
			Mage::throwException(Mage::helper('core')->__('Please specify either a category or a product, or both.'));
		}

		// generate id_path
		if ('id' === $type) {
			if (!$product) {
				return 'category/' . $category->getId();
			}
			if ($category && $category->getLevel() > 1) {
				return 'product/' . $product->getId() . '/' . $category->getId();
			}
			return 'product/' . $product->getId();
		}

		// generate request_path
		if ('request' === $type) {
			// for category
			if (!$product) {
				if ($category->getUrlKey() == '') {
					$urlKey = $this->getCategoryModel()->formatUrlKey($category->getName());
				} else {
					$urlKey = $this->getCategoryModel()->formatUrlKey($category->getUrlKey());
				}

				$categoryUrlSuffix = $this->getCategoryUrlSuffix($category->getStoreId());
				if (null === $parentPath) {
					$parentPath = $this->getResource()->getCategoryParentPath($category);
				} elseif ($parentPath == '/') {
					$parentPath = '';
				}
				$parentPath = Mage::helper('catalog/category')->getCategoryUrlPath($parentPath,
					true, $category->getStoreId());

				return $this->getUnusedPathByUrlkey($category->getStoreId(), $parentPath . $urlKey . $categoryUrlSuffix,
					$this->generatePath('id', null, $category), $urlKey
				);
			}

			// for product & category
			if (!$category) {
				Mage::throwException(Mage::helper('core')->__('A category object is required for determining the product request path.')); // why?
			}

			if ($product->getUrlKey() == '') {
				$urlKey = $this->getProductModel()->formatUrlKey($product->getName());
			} else {
				$urlKey = $this->getProductModel()->formatUrlKey($product->getUrlKey());
			}
			$productUrlSuffix = $this->getProductUrlSuffix($category->getStoreId());
			if ($category->getLevel() > 1) {
				// To ensure, that category has url path either from attribute or generated now
				$this->_addCategoryUrlPath($category);
				$categoryUrl = Mage::helper('catalog/category')->getCategoryUrlPath($category->getUrlPath(),
					false, $category->getStoreId());
				return $this->getUnusedPathByUrlkey($category->getStoreId(), $categoryUrl . '/' . $urlKey . $productUrlSuffix,
					$this->generatePath('id', $product, $category), $urlKey
				);
			}

			// for product only
			return $this->getUnusedPathByUrlkey($category->getStoreId(), $urlKey . $productUrlSuffix,
				$this->generatePath('id', $product), $urlKey
			);
		}

		// generate target_path
		if (!$product) {
			return 'catalog/category/view/id/' . $category->getId();
		}
		if ($category && $category->getLevel() > 1) {
			return 'catalog/product/view/id/' . $product->getId() . '/category/' . $category->getId();
		}
		return 'catalog/product/view/id/' . $product->getId();
	}


}