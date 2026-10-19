<?php
declare(strict_types=1);
namespace Webactueel\WordPressConnector\Adapters;
use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Security\Policy;
/** Allowlisted global Yoast snippet templates, not arbitrary options. */
final class YoastTemplateSettingsAdapter {
	private const KEYS = array(
		'home_title'=>'title-home-wpseo','home_description'=>'metadesc-home-wpseo',
		'page_title'=>'title-page','page_description'=>'metadesc-page',
		'post_title'=>'title-post','post_description'=>'metadesc-post',
		'product_title'=>'title-product','product_description'=>'metadesc-product',
		'category_title'=>'title-tax-category','category_description'=>'metadesc-tax-category',
		'tag_title'=>'title-tax-post_tag','tag_description'=>'metadesc-tax-post_tag',
		'author_title'=>'title-author-wpseo','author_description'=>'metadesc-author-wpseo',
		'date_archive_title'=>'title-archive-wpseo','date_archive_description'=>'metadesc-archive-wpseo',
		'search_results_title'=>'title-search-wpseo','not_found_title'=>'title-404-wpseo',
		'home_social_title'=>'open_graph_frontpage_title','home_social_description'=>'open_graph_frontpage_desc',
		'post_social_title'=>'social-title-post','post_social_description'=>'social-description-post',
		'page_social_title'=>'social-title-page','page_social_description'=>'social-description-page',
		'product_social_title'=>'social-title-product','product_social_description'=>'social-description-product',
		'category_social_title'=>'social-title-tax-category','category_social_description'=>'social-description-tax-category',
		'tag_social_title'=>'social-title-tax-post_tag','tag_social_description'=>'social-description-tax-post_tag',
		'author_social_title'=>'social-title-author-wpseo','author_social_description'=>'social-description-author-wpseo',
		'date_archive_social_title'=>'social-title-archive-wpseo','date_archive_social_description'=>'social-description-archive-wpseo',
		'breadcrumb_home_label'=>'breadcrumbs-home','breadcrumb_separator'=>'breadcrumbs-sep',
		'breadcrumb_prefix'=>'breadcrumbs-prefix','breadcrumb_archive_prefix'=>'breadcrumbs-archiveprefix',
		'breadcrumb_search_prefix'=>'breadcrumbs-searchprefix','breadcrumb_404_label'=>'breadcrumbs-404crumb',
		'product_category_title'=>'title-tax-product_cat',
		'product_category_description'=>'metadesc-tax-product_cat');
	public function register(Registry $registry): void {
		$security=array('privileged'=>true,'capability'=>'manage_options');
		$registry->register('yoast.templates.inspect',array($this,'inspect'),$security);
		$registry->register('yoast.templates.update',array($this,'update'),array_merge($security,array('mutation'=>true)));
		$registry->register('yoast.templates.restore',array($this,'restore'),array_merge($security,array('mutation'=>true)));
	}
	public function inspect(array $payload=array(),array $context=array()): array {
		$fields=$this->snapshot();
		if (Policy::publicRepositoryContext()) {
			return array('fields'=>array_intersect_key($fields,array_flip(array('category_title','product_category_title','product_title','product_description'))),'fingerprint'=>Fingerprint::make($fields));
		}
		return array('fields'=>$fields,'fingerprint'=>Fingerprint::make($fields));
	}
	public function update(array $payload,array $context): array {
		$changes=$this->validateFields($payload['fields']??null,false);
		$this->assertProviderSupportsFields($changes);
		$before=$this->snapshot();$after=array_replace($before,$changes);
		$result=array('before'=>$before,'after'=>$after,'_current_fingerprint'=>Fingerprint::make($before));
		if(!empty($context['dry_run']))return $result;
		$options=$this->options();
		foreach($changes as $key=>$value)$options[self::KEYS[$key]]=$value;
		update_option('wpseo_titles',$options);
		$verified=$this->snapshot();
		if($verified!==$after){
			foreach($changes as $key=>$value){
				if(null===$before[$key])unset($options[self::KEYS[$key]]);
				else $options[self::KEYS[$key]]=$before[$key];
			}
			update_option('wpseo_titles',$options);
			throw new RuntimeException('Yoast template readback mismatch; requested fields restored.');
		}
		$result['after']=$verified;
		$result['_rollback']=array('action'=>'yoast.templates.restore','payload'=>array(
			'fields'=>array_intersect_key($before,$changes),
			'expected_after_fingerprint'=>Fingerprint::make($verified)));
		return $result;
	}
	public function restore(array $payload,array $context): array {
		if(empty($context['rollback_mode']))throw new RuntimeException('Rollback only.');
		$current=$this->snapshot();
		$expected=$payload['expected_after_fingerprint']??'';
		if(!is_string($expected)||!hash_equals(Fingerprint::make($current),$expected))
			throw new RuntimeException('Yoast templates changed since write.');
		$previous=$this->validateFields($payload['fields']??null,true);
		if(!empty($context['dry_run']))return array('would_restore'=>array_keys($previous));
		$options=$this->options();
		foreach($previous as $key=>$value){
			if($value===null)unset($options[self::KEYS[$key]]);
			else $options[self::KEYS[$key]]=$value;
		}
		update_option('wpseo_titles',$options);
		if(array_intersect_key($this->snapshot(),$previous)!==$previous)
			throw new RuntimeException('Yoast template rollback readback mismatch.');
		return array('restored'=>true);
	}
	private function validateFields($fields,bool $restore): array {
		if(!is_array($fields)||!$fields||count($fields)>count(self::KEYS))
			throw new RuntimeException('Expected bounded template fields.');
		$clean=array();
		foreach($fields as $key=>$value){
			if(!is_string($key)||!isset(self::KEYS[$key])||
				(!is_string($value)&&!($restore&&$value===null)))
				throw new RuntimeException('Unsupported Yoast template field.');
			if($value!==null){
				$maxBytes = (strpos($key,'description')!==false ? 320 : 190);
				if ($key === 'breadcrumb_separator') {
					$maxBytes = 20;
				} elseif (strpos($key, 'breadcrumb_') === 0) {
					$maxBytes = 120;
				}
				if(strlen($value)>$maxBytes||
					(!$restore&&trim($value)===''))
					throw new RuntimeException('Invalid template length.');
				$value=$restore?$value:sanitize_text_field($value);
				if(!$restore&&trim($value)==='')throw new RuntimeException('Invalid template text.');
			}
			$clean[$key]=$value;
		}
		return $clean;
	}
	private function assertProviderSupportsFields(array $changes): void {
		if (Policy::publicRepositoryContext()) {
			// Public GitHub requests are deliberately narrower than the private
			// Yoast template manager. Recheck on the WordPress server as well
			// as in the repository's request validator.
			foreach ($changes as $key => $value) {
				if (! in_array($key, array('category_title', 'product_category_title',
					'product_title', 'product_description'), true)) {
					throw new RuntimeException('Public Yoast template field is not allowed.');
				}
				$token = in_array($key, array('category_title','product_category_title'), true)
					? '%%term_title%%' : ($key === 'product_title' ? '%%title%%' : '%%excerpt%%');
				if (! is_string($value) || false === strpos($value, $token)) {
					throw new RuntimeException('Public Yoast template requires its content-specific variable.');
				}
			}
			return;
		}
		if (!class_exists('WPSEO_Options') || !method_exists('WPSEO_Options','get_option_instance')) {
			throw new RuntimeException('Yoast option schema API is unavailable.');
		}
		$instance=\WPSEO_Options::get_option_instance('wpseo_titles');
		if (!is_object($instance) || !method_exists($instance,'get_defaults')) {
			throw new RuntimeException('Installed Yoast version has no verified titles schema.');
		}
		$defaults=$instance->get_defaults();
		if (!is_array($defaults)) {
			throw new RuntimeException('Installed Yoast titles schema was invalid.');
		}
		foreach ($changes as $key=>$value) {
			if (!array_key_exists(self::KEYS[$key],$defaults)) {
				throw new RuntimeException('Installed Yoast does not recognize the requested template field.');
			}
			if (method_exists($instance,'is_disabled') && $instance->is_disabled(self::KEYS[$key])) {
				throw new RuntimeException('The requested Yoast field is disabled by network/site policy.');
			}
		}
	}

	private function options(): array {
		$value=get_option('wpseo_titles',null);
		if(!is_array($value))throw new RuntimeException('Yoast settings unavailable.');
		return $value;
	}
	private function snapshot(): array {
		$opts=$this->options();$fields=array();
		foreach(self::KEYS as $key=>$option)$fields[$key]=isset($opts[$option])?(string)$opts[$option]:null;
		return $fields;
	}
}
