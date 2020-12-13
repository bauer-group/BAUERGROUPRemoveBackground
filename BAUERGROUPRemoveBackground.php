<?php

/* 
 *  Bootstrapper for BAUERGROUPRemoveBackground Class 
 */

namespace BAUERGROUPRemoveBackground;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\UpdateContext;


class BAUERGROUPRemoveBackground extends Plugin
{
	//---------------------------------------------------------------------
	//Common Bootstrap Code START - Version 1.0
	
	//Internals START	
	private function getCacheArray()
	{
		return [
			InstallContext::CACHE_LIST_DEFAULT
		];
	}
	//Internals STOP
	
	//Setup START
	public function install(InstallContext $context)
	{
		$this->InstallCustomizations();

		$context->scheduleClearCache($this->getCacheArray());
		parent::install($context);
	}
 
	public function uninstall(UninstallContext $context)
	{
		$this->UninstallCustomizations();

		$context->scheduleClearCache($this->getCacheArray());
		parent::uninstall($context);
	}
 
	public function activate(ActivateContext $context)
	{
		$context->scheduleClearCache($this->getCacheArray());
		parent::activate($context);
	}
 
	public function deactivate(DeactivateContext $context)
	{
		$context->scheduleClearCache($this->getCacheArray());
		parent::deactivate($context);
	}
	
	public function update(UpdateContext $context) 
	{
		$context->scheduleClearCache($this->getCacheArray());
		parent::update($context);
	}
	//Setup END
	
	//Common Bootstrap Code END
	//---------------------------------------------------------------------
	
	private function InstallCustomizations()
	{

	}

	private function UninstallCustomizations()
	{

	}
}

?>
