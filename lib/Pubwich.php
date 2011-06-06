<?php
	defined('PUBWICH') or die('No direct access allowed.');

	define( 'PUBWICH_VERSION', '1.5' );

	/**
	 * @classname Pubwich
	 */
	class Pubwich {

		static private $services, $classes, $columns, $theme_url, $theme_path, $header_links, $gettext = null;

		/**
		 * Application initialisation
		 */
		static public function init() {

			// Let’s modify the `include_path`
			$path = dirname(__FILE__).'/';
			$path_pear = dirname(__FILE__).'/PEAR/';
			set_include_path( $path . PATH_SEPARATOR . $path_pear . PATH_SEPARATOR . get_include_path() );

			require_once( 'PEAR.php' );

			// Exception class
			require( 'PubwichError.php' );

			// Configuration files
			if ( !file_exists( dirname(__FILE__)."/../cfg/config.php" ) ) {
				throw new PubwichError( 'You must rename <code>/cfg/config.sample.php</code> to <code>/cfg/config.php</code> and edit the Web service configuration details.' );
			} else {
				require( dirname(__FILE__) . '/../cfg/config.php' );
			}

			// Internationalization class
			if ( defined('PUBWICH_LANG') && PUBWICH_LANG != '' ) {
				require( 'Gettext/streams.php' );
				require( 'Gettext/gettext.php' );
				self::$gettext = @new gettext_reader( new FileReader( dirname(__FILE__).'/../lang/'.PUBWICH_LANG.'/pubwich-'.PUBWICH_LANG.'.mo' ) );
			}

			// JSON support
			if ( !function_exists( 'json_decode' ) ) {
				require_once( dirname(__FILE__) . '/../Zend/Json.php' );
			}
			// Events logger (and first message)
			require('PubwichLog.php');
			PubwichLog::init();
			PubwichLog::log( 1, Pubwich::_("Pubwich object initialization") );

			// Theme
			self::$theme_url = PUBWICH_URL . 'themes/' . PUBWICH_THEME;
			self::$theme_path = dirname(__FILE__) . '/../themes/' . PUBWICH_THEME;
			require( 'PubwichTemplate.php' );

			// PHP objects creation
			self::setClasses();

			// Other classes
			require( 'FileFetcher.php' );
			require( 'Cache/Lite.php' );

			if ( !defined( 'PUBWICH_CRON' ) ) {
				require_once( 'Mustache.php/Mustache.php' );
			}

		}

		/**
		 * Translate a string according to the defined locale/
		 *
		 * @param string $single
		 * @return string
		 */
		static public function _($single, $plural=false, $number=false) {
            // gettext lib throws notices, so we turn off all error reporting
            // for the translation process

            if ($plural===false && $number===false)
			return (self::$gettext ) ? @self::$gettext->translate( $single ) : $string;

            return (self::$gettext ) ? @self::$gettext->ngettext($single, $plural, $number) : $string;
		}

		/**
		 * Set the $classes array
		 *
		 * @return void
		 */
		static public function setClasses() {
			require( 'Services/Service.php' );
			$columnCounter = 0;
			foreach ( self::getServices() as $column ) {
				$columnCounter++;
				self::$columns[$columnCounter] = array();
				foreach( $column as $service ) {

					list( $name, $variable, $config ) = $service;
					$name = ucfirst($name);
					$service_instance = strtolower( $name . '_' . $variable );
					${$service_instance} = Pubwich::loadService( $name, $config );
					${$service_instance}->setVariable( $variable );
					self::$classes[$variable] = ${$service_instance};
					self::$columns[$columnCounter][] = &${$service_instance};

				}
			}
		}

		/**
		 * loadConfiguredServices() is a synomym to setClasses()
		 *
		 * @return void
		 */
		static public function loadConfiguredServices() {
			self::setClasses();
            return;
		}

		/**
		 * Get an array with all intern IDs of active services
		 *
		 * @return array
		 */
		static public function listActiveServices() {
			$services = self::$classes;
            if (!is_array($services)) return array();
            return array_keys($services);
        }

		/**
		 * Get an currently active service object
		 *
         * @param string $id ID of active service
		 * @return object
		 */
		static public function getActiveService($service_id) {
			$services = self::$classes;
            if (!isset($services[$service_id])) return false;
            return $services[$service_id];
        }

		/**
		 * Renders the template according to the current theme
		 *
		 * @return void
		 */
		static public function renderTemplate() {

			if ( !file_exists(self::getThemePath()."/index.tpl.php") ) {
				throw new PubwichError( sprintf( Pubwich::_( 'The file <code>%s</code> was not found. It has to be there.' ), '/themes/'.PUBWICH_THEME.'/index.tpl.php' ) );
			}

			if ( file_exists( self::getThemePath()."/functions.php" ) ) {
				require( self::getThemePath()."/functions.php" );
				self::applyTheme();
			}

			include (self::getThemePath() . '/index.tpl.php' );
		}

		/**
		 * @return string
		 */
		static public function getThemePath() {
			return self::$theme_path;
		}

		/**
		 * @return string
		 */
		static public function getThemeUrl() {
			return self::$theme_url;
		}

		/**
		 * Set the services to use
		 *
		 * @param array $services
		 * @return void
		 */
		static public function setServices( $services = array() ) {
			self::$services = $services;
		}

		/**
		 * @return array
		 */
		static public function getServices( ) {
			return self::$services;
		}

		/**
		 * Require a service file (according to the “cascade”)
		 *
		 * @param string $service Service
		 * @return bool
		 */
		static public function requireServiceFile( $service ) {
			$files = array(
				// theme-specific service
				self::$theme_path . '/lib/Services/' . $service . '.php',
				// pubwich custom service
				dirname(__FILE__) . '/Services/Custom/' . $service . '.php',
				// pubwich default service
				dirname(__FILE__) . '/Services/' . $service . '.php'
			);

			$file_included = false;
			foreach( $files as $file ) {
				if ( file_exists( $file ) ) {
					require_once( $file );
					$file_included = true;
					break;
				}
			}
			return $file_included;
		}

		/**
		 * Load a service file
		 *
		 * @param string $service The service name
		 * @param array $config The parameters
		 * @return Service
		 */
		static public function loadService( $service, $config ) {
			PubwichLog::log( 1, sprintf( Pubwich::_('Loading %s service'), $service ) );

			$file_included = self::requireServiceFile( $service );

			if ( !$file_included ) {
				throw new PubwichError( sprintf( Pubwich::_( 'You told Pubwich to use the %s service, but the file <code>%s</code> couldn’t be found.' ), $service, $service.'.php' ) );
			}

			$classname = ( isset($config['method']) && $config['method'] ) ? $config['method'] : $service;
			if ( !class_exists( $classname ) ) {
				throw new PubwichError( sprintf( Pubwich::_( 'The class %s doesn\'t exist. Check your configuration file for inexistent services or methods.' ), $classname ) );
			}

			return new $classname( $config );
		}

		/**
		 * Rebuild the cache for each defined service
		 *
		 * @return void
		 */
		static public function rebuildCache() {

			PubwichLog::log( 1, Pubwich::_("Building application cache") );

			// First, let’s flush the cache directory
			$files = scandir(CACHE_LOCATION);
			foreach ( $files as $file ) {
				if ( substr( $file, 0, 1 ) != "." ) {
					unlink( CACHE_LOCATION . $file );
				}
			}

			// Then, we fetch everything
			foreach ( self::$classes as &$classe ) {
				$classe->buildCache();
			}

		}

		/**
		 * Apply box and items templates
		 *
		 * @return void
		 */
		static private function applyTheme() {

			if ( function_exists( 'boxTemplate' ) ) {
				$boxTemplate = call_user_func( 'boxTemplate' );
			} else {
				throw new PubwichError( Pubwich::_('You must define a boxTemplate function in your theme\'s functions.php file.') );
			}

			foreach( self::$classes as $class ) {

				$functions = array();
				$parent = get_parent_class( $class );
				$classname = get_class( $class );
				$variable = $class->getVariable();

				if ( !$class->getBoxTemplate()->hasTemplate() && $boxTemplate ) {
					$class->setBoxTemplate( $boxTemplate );
				}

				if ( $parent != 'Service' ) {
					$functions = array(
						$parent,
						$parent . '_' . $classname,
						$parent . '_' . $classname . '_' . $variable,
					);
				} else {
					$functions = array(
						$classname,
						$classname . '_' . $variable,
					);
				}

				foreach ( $functions as $f ) {
					$box_f = $f . '_boxTemplate';
					$item_f = $f . '_itemTemplate';

					if ( function_exists( $box_f ) ) {
						$class->setBoxTemplate( call_user_func( $box_f ) );
					}

					if ( function_exists( $item_f ) ) {
						$class->setItemTemplate( call_user_func( $item_f ) );
					}
				}
			}
		}

		/**
		 * Displays the generated HTML code
		 *
		 * @return string
		 */
		static public function getLoop() {

			$columnTemplate = function_exists( 'columnTemplate' ) ? call_user_func( 'columnTemplate' ) : '<div class="col{{{number}}}">{{{content}}}</div>';
			$layoutTemplateDefined = false;

			if ( function_exists( 'layoutTemplate' ) ) {
				$layoutTemplate = call_user_func( 'layoutTemplate' );
				$layoutTemplateDefined = true;
			} else {
				$layoutTemplate = '';
			}

			$output_columns = array();
			$m = new Mustache;
			foreach( self::$columns as $col => $classes ) {
				$boxes = '';
				foreach( $classes as $class ) {
					$boxes .= $class->renderBox();
				}
				$output_columns['col'.$col] = $m->render($columnTemplate, array('number'=>$col, 'content'=>$boxes));

				if ( !$layoutTemplateDefined ) {
					$layoutTemplate .= '{{{col'.$col.'}}} ';
				}
			}
			return $m->render($layoutTemplate, $output_columns);
		}

		/*
		 * Header hook
		 *
		 * @return string
		 */
		static public function getHeader() {
			$output = '';
			foreach ( self::$classes as $class ) {
				$link = $class->getHeaderLink();
				if ( $link ) {
					$output .= '<link rel="alternate" title="'.$class->title.' - '.$class->description.'" href="'.htmlspecialchars( $link['url'] ).'" type="'.$link['type'].'"/>'."\n";
				}
			}
			return $output;
		}

		/*
		 * Footer hook
		 *
		 * @return string
		 */
		static public function getFooter() {
			return '';
		}

		/**
		 * Return a date in a relative format
		 * Based on: http://snippets.dzone.com/posts/show/5565
		 *
		 * @param $original Date timestamp
		 * @return string
		 */
		static public function time_since( $original ) {

			$original = strtotime( $original );
			$today = time();
			$since = $today - $original;

			if ( $since < 0 ) {
				return sprintf( Pubwich::_('just moments ago'), $since );
			}

			if ( $since >= ( 7 * 24 * 60 * 60 ) ) {
				return strftime( Pubwich::_('%e %B at %H:%M'), $original );
			}

            $timechunks = array(
                array(60, 60,'1 second ago', '%d seconds ago'),
                array(60*60, 60, '1 minute ago', '%d minutes ago'),
                array(24*60*60, 24, '1 hour ago', '%d hours ago'),
                array(7*24*60*60, 7, '1 day ago', '%d days ago'),
			);

			for ( $i = 0, $j = count( $timechunks ); $i < $j; $i++ ) {
				$seconds = $timechunks[$i][0];
				$string_single = $timechunks[$i][2];
                $string_plural = $timechunks[$i][3];
				if ( $since < $seconds) {
                    $count = floor( $since / ($seconds/$timechunks[$i][1]));
					return sprintf( Pubwich::_($string_single, $string_plural, $count), $count );
				}
			}

		}

		/**
		 * @param string $str JSON-encoded object
		 * @return object PHP object
		 */
		public function json_decode( $str ) {
			if ( function_exists( 'json_decode' ) ) {
				return json_decode( $str );
			} else {
				return Zend_Json::decode( $str, Zend_Json::TYPE_OBJECT );
			}
		}

		/**
		 * @return void
         * @since 20110531
		 */
        static public function processFilters() {

            /* the first and very simple approach to plug filter methods in,
             * they can be used to filter the now processed data before output
             *
             * for now put all filter functions in a filters.php in the theme
             * path, this should been enhanced later with paths for global
             * core filter, user filters, theme filters
             */

			if ( file_exists( self::getThemePath()."/filters.php" ) ) {
				require( self::getThemePath()."/filters.php" );
			}
            
			foreach( self::$classes as $service ) {

				$filtermethods = array();
				$parent = get_parent_class( $service );
				$classname = get_class( $service );
				$variable = $service->getVariable();

                $filtermethods = array(
                    $parent,
                    $parent . '_' . $classname,
                    $parent . '_' . $classname . '_' . $variable,
                );

				foreach ( $filtermethods as $filter ) {
					$stream_filter = $filter . '_filterStream';
					$item_filter = $filter . '_filterItem';

					if ( function_exists($stream_filter  ) ) {
						$stream_filter($service);
					}

					if ( function_exists( $item_filter ) && isset($service->data_processed) && is_array($service->data_processed)) {
                        foreach ($service->data_processed as $i => $v) {
                            $item_filter(&$service->data_processed[$i]);
                        }
					}
				}
			}

            return;
        }

		/**
		 * @return void
         * @since 20110531
		 */
        static public function processServices() {
			foreach (self::$classes as &$classe) {
				$classe->init();
                $classe->prepareService();
			}
            return;
        }

	}
