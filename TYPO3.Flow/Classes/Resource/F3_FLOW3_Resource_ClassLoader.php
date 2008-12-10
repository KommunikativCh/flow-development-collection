<?php
declare(ENCODING = 'utf-8');
namespace F3::FLOW3::Resource;

/*                                                                        *
 * This script is part of the TYPO3 project - inspiring people to share!  *
 *                                                                        *
 * TYPO3 is free software; you can redistribute it and/or modify it under *
 * the terms of the GNU General Public License version 2 as published by  *
 * the Free Software Foundation.                                          *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General      *
 * Public License for more details.                                       *
 *                                                                        */

/**
 * @package FLOW3
 * @subpackage Resource
 * @version $Id:F3::FLOW3::Object::ClassLoader.php 203 2007-03-30 13:17:37Z robert $
 */

require(FLOW3_PATH_FLOW3 . 'Package/F3_FLOW3_Package_PackageInterface.php');
require(FLOW3_PATH_FLOW3 . 'Package/F3_FLOW3_Package_Package.php');

/**
 * Class Loader implementation which loads .php files found in the classes
 * directory of an object.
 *
 * @package FLOW3
 * @subpackage Resource
 * @version $Id:F3::FLOW3::Object::ClassLoader.php 203 2007-03-30 13:17:37Z robert $
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License, version 2
 */
class ClassLoader {

	/**
	 * @var array Class names and their absolute path and filename of specifically registered classes. Used for classes which don't follow the F3::Package::Object scheme.
	 */
	protected $specialClassNamesAndPaths = array();

	/**
	 * @var string Absolute path of the Packages/ directory
	 */
	protected $packagesDirectory;

	/**
	 * Constructs the class loader
	 *
	 * @param string $packagesDirectory Absolute path of the Packages/ directory.
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function __construct($packagesDirectory) {
		$this->packagesDirectory = $packagesDirectory;
	}

	/**
	 * Loads php files containing classes or interfaces found in the classes directory of
	 * a package and specifically registered classes.
	 *
	 * @param string $className Name of the class/interface to load
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function loadClass($className) {
		if (isset($this->specialClassNamesAndPaths[$className])) {
			$classFilePathAndName = $this->specialClassNamesAndPaths[$className];
		} else {
			$classNameParts = explode('::', $className);
			if (is_array($classNameParts) && $classNameParts[0] == 'F3') {
				$classFilePathAndName = $this->packagesDirectory . $classNameParts[1] . '/' . F3::FLOW3::Package::Package::DIRECTORY_CLASSES;
				$classFilePathAndName .= implode(array_slice($classNameParts, 2, -1), '/') . '/';
				$classFilePathAndName .= str_replace('::', '_', $className) . '.php';
			}
		}
		if (isset($classFilePathAndName)) require($classFilePathAndName);
	}

	/**
	 * Explicitly sets a file path and name which holds the implementation of
	 * the given class.
	 *
	 * @param string $className Name of the class to register
	 * @param string $classFilePathAndName Absolute path and file name of the file holding the class implementation
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 * @see F3::FLOW3::Resource::Manager
	 */
	public function setSpecialClassNameAndPath($className, $path) {
		$this->specialClassNamesAndPaths[$className] = $path;
	}
}

?>