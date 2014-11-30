<?php
namespace vestibulum;

use Latte\Runtime\Filters;

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/Metadata.php';
require_once __DIR__ . '/Pages.php';
require_once __DIR__ . '/Render.php';
require_once __DIR__ . '/Page.php';

// external library
require_once __DIR__ . '/../vendor/latte/latte/src/latte.php';
require_once __DIR__ . '/../vendor/erusev/parsedown/Parsedown.php';

/**
 * Vestibulum: Really deathly simple CMS
 *
 * @author Roman Ožana <ozana@omdesign.cz>
 */
class Vestibulum extends \stdClass {

	use Render;

	/** @var Vestibulum */
	public $cms;
	/** @var Page */
	public $page;
	/** @var string */
	public $content;
	/** @var \stdClass */
	public $config;

	public function __construct() {
		$this->cms = $this;
		$this->config = config();
		$this->requires();
		$this->page = $this->getPage((array)config()->meta);

		is_file(getcwd() . '/functions.php') ? include_once getcwd() . '/functions.php' : null;
	}

	/**
	 * Requires PHP
	 */
	public function requires() {
		// src index.php of request.php
		is_file($php = content(request() . '/index.php')) ? include_once $php : null ||
		is_file($php = content(request() . '.php')) ? include_once $php : null;

		// cwd index.php of request.php
		is_file($php = getcwd() . request() . '/index.php') ? include_once $php : null ||
		is_file($php = getcwd() . request() . '.php') ? include_once $php : null;
	}

	/**
	 * Return current file
	 *
	 * @param array $meta
	 * @return Page
	 */
	public function getPage(array $meta = []) {
		$files = [
			content(request()),
			content(dirname(request()) . '/404'),
			content('/404')
		];

		foreach ($files as $path) {
			if ($file = Page::fromPath($path, $meta)) return $file;
		}

		return new Page(content(), array_merge($meta, ['status' => 404]), '<h1>404 Page not found</h1>'); // last chance
	}


	/**
	 * @return string
	 */
	public function render() {

		// HTTP status code
		if ($code = isset($this->page->status) ? $this->page->status : null) status($code);

		// PHTML file execute
		if ($this->page->is('phtml')) {
			extract(get_object_vars($this), EXTR_SKIP);
			ob_start();
			require $this->page;
			return ob_get_clean();
		}

		// replace {url} with current server URL
		if ($this->page->is('md') || $this->page->is('html')) {
			$this->content = preg_replace_callback(
				"/{url\s?['\"]?([^\"'}]*)['\"]?}/", function ($m) {
					return Filters::safeUrl(url(end($m)));
				},
				$this->page->getContent()
			);
		}

		// Read markdown from cache or recompile
		if ($this->page->is('md')) {
			$this->content = cache(
				$file = tmp($this->page->getName() . '-' . md5($this->page) . '.html'),
				function () {
					return \Parsedown::instance()->text($this->content);
				},
				$this->page->getMTime() > @filemtime($file)
			);
		}

		$template = pathinfo($this->page->template, PATHINFO_EXTENSION);

		// phtml - for those who have an performance obsession :-)
		if ($template === 'phtml' || $template === 'php') {
			extract(get_object_vars($this), EXTR_SKIP);
			ob_start();
			require $this->page->template;
			return ob_get_clean();
		}

		// Latte - for lazy people :-)
		if ($template === 'latte') {
			$latte = $this->getLatte();
			if (isset($this->page->latte) || $this->page->getExtension() === 'latte') {
				$this->content = $latte->renderToString($this->page, get_object_vars($this));
			}

			return $latte->renderToString($this->page->template, get_object_vars($this));
		}

		return $this->content;
	}
}