<?php
/*
	Wikify

	1. Read and parse "auto topics" page
	2. Scan for each word
	3. For each word found wikify it
*/

 
/**
 * Protect against register_globals vulnerabilities.
 * This line must be present before any global variable is referenced.
 */
if( !defined( 'MEDIAWIKI' ) ) {
	echo( "This is an extension to the MediaWiki package and cannot be run standalone.\n" );
	die( -1 );
}

// Page to retrieve auto topics from
$wgSiteWideLinksPageName = 'MediaWiki:Wikify';
$wgSiteWideAcronymsPageName = 'Acronyms';
$wgSiteWideReferencesPageName = 'References';


// Extension credits that will show up on Special:Version    
$wgExtensionCredits['parserhook'][] = array(
    'path'         => __FILE__,
	'name'         => 'Wikify',
	'version'      => '1.0',
	'author'       => 'Lance Gatlin', 
	'url'          => '',
	'description'  => 'Automatically link terms, expands first acronym and creates citation for text within a <nowiki><wikify></nowiki> tag.'
);
  
$wgHooks['ParserFirstCallInit'][] = 'efWikifyInit';
$wgHooks['ParserBeforeTidy'][] = 'efWikifyBeforeTidy';
 
function efWikifyInit( &$parser ) {
	$parser->setHook( 'wikify', 'efWikifyRender' );
	return true;
}

$gWikify_hasRun = false;
$gWikifyLinks = array();
$gWikifyAcronyms = array();
$gWikifyReferences = array();
$gWikifyInputParsed = array();


function str_replace_once($search, $replace, $subject, &$replaceCount = null) {
	if(is_array($search) && is_array($replace))
	{
		$temp = 0;
		if($replaceCount != null)
			$replaceCount = 0;

		$max_i = count($search) < count($replace) ? count($search) : count($replace);
		for($i=0;$i<$max_i;$i++)
		{
			$subject = str_replace_once_imp($search[$i], $replace[$i], $subject, $temp);
			if($replaceCount != null)
				$replaceCount += $temp;
		}
	}
	else
		$subject = str_replace_once_imp($search, $replace, $subject, $replaceCount);

	return $subject;
}

function str_replace_once_imp($search, $replace, $subject, &$replaceCount) {
	$firstChar = strpos($subject, $search);
	
	if($firstChar !== false) 
	{
		if($replaceCount !== null)
			$replaceCount = 1;
		$beforeStr = substr($subject,0,$firstChar);
		$afterStr = substr($subject, $firstChar + strlen($search));
		return $beforeStr.$replace.$afterStr;

	}
	else
		if($replaceCount !== null)
			$replaceCount = 0;
		
	return $subject;
}

function efWikifyParseAcronyms($pageText)
{
  global $gWikifyAcronyms;
  
  preg_match_all("/;(.+)\s*:(.+)/", $pageText, $acronyms, PREG_SET_ORDER);
  
  foreach($acronyms as $acronym)
  {
    $search = $acronym[1];
    $replace = $acronym[2] . " ($search)";
    
    // add a huge number to partition acronyms to the top of the list
    // removed recurse so that plural acronyms expand correctly
    $gWikifyAcronyms[] = array(strlen($search), $search, $replace, false);
  }
  
  rsort($gWikifyAcronyms);	
}

function efWikifyParseReferences($pageText)
{
  global $gWikifyReferences;
  
  preg_match_all("/#(\[.+\])\s*:\s*(.+)/", $pageText, $references, PREG_SET_ORDER);
  
  foreach($references as $reference)
  {
    $search = $reference[1];
    $replace = "<ref name=\"$search\">$search: " . $reference[2] . "</ref>";
    
    // add a huge number to partition acronyms to the top of the list
    // removed recurse so that plural acronyms expand correctly
    $gWikifyReferences[] = array(strlen($search), $search, $replace);
//			$gWikifyReferences[$search] = $replace;
  }

  rsort($gWikifyReferences);
}

function efWikifyParseLinks($pageText)
{
  global $gWikifyLinks;
  
  preg_match_all("/'(.+)'\s*=>\s*'(.+)'\s*(once|recurse)*\s*(once|recurse)*/", $pageText, $translations, PREG_SET_ORDER);

  foreach($translations as $translation)
  {
    $search = $translation[1];
    $replace = $translation[2];
    $once = false;
    $recurse = false;
    
    for($i=3;$i<count($translation);$i++) {
      switch($translation[$i])
      {
        case 'once' : $once = true; break;
        case 'recurse' : $recurse = true; break;
      }
    }
    $gWikifyLinks[] = array(strlen($search), $search, $replace, $once, $recurse, false);
  }
  
  rsort($gWikifyLinks);
}

function efWikifyAcronymsPage($pageName)
{
  $title = Title::newFromText($pageName);
  if(!is_object($title))
    return;
  $r = Revision::newFromTitle($title);
  if(!is_object($r))
    return;
    
  efWikifyParseAcronyms($r->getText());    
}

function efWikifyReferencesPage($pageName)
{
  $title = Title::newFromText($pageName);
  if(!is_object($title))
    return;
  $r = Revision::newFromTitle($title);
  if(!is_object($r))
    return;
    
  efWikifyParseReferences($r->getText());
}

function efWikifyLinksPage($pageName)
{
  $title = Title::newFromText($pageName);
  if(!is_object($title))
    return;
  $r = Revision::newFromTitle($title);
  if(!is_object($r))
    return;
    
  efWikifyParseLinks($r->getText());
}

function efWikifyRead($parser)
{
	global $gWikify_hasRun, $wgSiteWideLinksPageName, $wgSiteWideAcronymsPageName, $wgSiteWideReferencesPageName;
  
	if(!$gWikify_hasRun)
	{
		// Wikify Acronyms
    efWikifyAcronymsPage($wgSiteWideAcronymsPageName);
    
		// Wikify References
    efWikifyReferencesPage($wgSiteWideReferencesPageName);
    
		// Wikify Links
    efWikifyLinksPage($wgSiteWideLinksPageName);
    
		$gWikify_hasRun = true;
	}
}

function escapeRegexChars($string)
{
	// esccape all regex special chars
	static $regexMetachars = array( "[","]","\\","/","^","\$",".","|","?","*","+","(",")" );
	static $escapedRegexMetachars = array('\[','\]','\\','\/','\^','\$','\.','\|','\?','\*','\+','\(','\)');

	return str_replace($regexMetachars,$escapedRegexMetachars,$string);
}

function efWikifyRender($input, $args, $parser, $frame ) {
	global $gWikifyLinks, $gWikifyAcronyms, $gWikifyReferences, $gWikifyInputParsed;
	
	$dom = $parser->getPreprocessor()->preprocessToObj($input);
	$input = $frame->expand($dom);

  // Parse page embedded acronyms, references and links
	$parse = isset($args['parse']) ? $args['parse'] : '';
  switch($parse)
  {
    case 'acronyms' :
      efWikifyParseAcronyms($input);
      // after parsing return a hidden marker that will be either removed or replaced with the input before tidy
      return '<!-- WIKIFY_PARSE_BEGIN -->' . $parser->recursiveTagParse($input, $frame) . '<!-- WIKIFY_PARSE_END -->';
//      $marker = '<!-- WIKIFY_INPUT_PARSED_' . count($gWikifyInputParsed) . ' -->';
//      $gWikifyInputParsed[] = array($marker, $parser->recursiveTagParse($input,$frame));
//      return $marker;
      
    case 'references' :
      efWikifyParseReferences($input);
      return '<!-- WIKIFY_PARSE_BEGIN -->' . $parser->recursiveTagParse($input, $frame) . '<!-- WIKIFY_PARSE_END -->';
      // after parsing return a hidden marker that will be either removed or replaced with the input before tidy
//      $marker = '<!-- WIKIFY_INPUT_PARSED_' . count($gWikifyInputParsed) . ' -->';
//      $gWikifyInputParsed[] = array($marker, $parser->recursiveTagParse($input,$frame));
//      return $marker;
    case 'links' :
      efWikifyParseLinks($input);
      return '<!-- WIKIFY_PARSE_BEGIN -->' . $parser->recursiveTagParse($input, $frame) . '<!-- WIKIFY_PARSE_END -->';
      // after parsing return a hidden marker that will be either removed or replaced with the input before tidy
//      $marker = '<!-- WIKIFY_INPUT_PARSED_' . count($gWikifyInputParsed) . ' -->';
//      $gWikifyInputParsed[] = array($marker, $parser->recursiveTagParse($input,$frame));
//      return $marker;
  }
  
  // Read site wide acronyms, references and links
	efWikifyRead($parser);
	
	// Parse the input first to replace [[internal]] [external] now so that they can't be accidently replaced during acronym/wikify processing
	// expand variables {{{1}}}	
	$input = $parser->recursiveTagParse($input, $frame);

	$replaceOnce = isset($args['replaceonce']) ? $args['replaceonce'] : false;
	$doReferences = isset($args['references']) ? $args['references'] : true;
	$addReferences = isset($args['addreferences']) ? $args['addreferences'] : '';
	$addReferences = explode(' ', $addReferences);
	
	$doAcronyms = isset($args['acronyms']) ? $args['acronyms'] : true;
	$allAcronyms = false;
	if($doAcronyms === 'all')
	{
		$allAcronyms = true;
		$doAcronyms = true;
	}
	$doLinks = isset($args['links']) ? $args['links'] : true;
	$noExpandAcronyms = isset($args['noacronym']) ? $args['noacronym'] : '';
	$noExpandAcronyms = explode(' ', $noExpandAcronyms);
	
	// add references specifically in addreferences attribute
	for($i=0;$i<count($gWikifyReferences);$i++)
	{
		list($unused, $search, $replace) = $gWikifyReferences[$i];

		if(in_array($search, $addReferences))
			$parser->recursiveTagParse($replace);
	}

	// handle the nill element case
	// make sure we process any noacronym cases to mean turn off that acronym for the entire page then return nothing
	if(strlen($input) == 0)
	{
		for($i=0;$i<count($gWikifyAcronyms);$i++)
		{
			list($unused, $search, $replace, $replacedOnceAlready) = $gWikifyAcronyms[$i];

			if(in_array($search, $noExpandAcronyms))
				$gWikifyAcronyms[$i][3] = true;
		}
		return '';
	}

	$text = $input;

	$rFinalReplace = array(); $rFinalSearch = array();

	if($doReferences)
	{
		// References
		// two-stage not for recursion worries but to allow <ref> to create footnotes in correct order
		for($i=0;$i<count($gWikifyReferences);$i++)
		{
			list($unused, $search, $replace) = $gWikifyReferences[$i];

			if(strpos($text, $search) === false)
				continue;

			$replace = $parser->recursiveTagParse($replace);

			// look for [Reference-Ref] that is an independent word
			// Replace it with "Reference Ref"[1]
			$temp = "@@@WIKIFY$i-1@@@";
			$rFinalSearch[] = $temp;
			$rFinalReplace[] = '<b>' . $search . '</b>' . $replace;
			
			$text = preg_replace('/(\s|^)' . escapeRegexChars($search) . '([^a-zA-Z0-9_-]|$)/', '\1' . $temp . '\2', $text);

			// replace all other cases such as blahblah[Reference-ref]
			// with blahblah[1]
			$temp = "@@@WIKIFY$i-2@@@";
			$rFinalSearch[] = $temp;
			$rFinalReplace[] = $replace;

			$text = str_replace($search, $temp, $text);
		}

		// perform temporary to final replace
//		$text = str_replace($finalSearch, $finalReplace, $text);
	}

	// Parse the input first to replace [[internal]] [external] now so that they can't be accidently replaced during acronym/wikify processing
//	$text = $parser->recursiveTagParse( $text, $frame );

	if($doAcronyms)
	{
		// Acronyms
		// two stage to prevent recursion
		$aFinalReplace = array(); $aFinalSearch = array();

		for($i=0;$i<count($gWikifyAcronyms);$i++)
		{
			list($unused, $search, $replace, $replacedOnceAlready) = $gWikifyAcronyms[$i];

			if($allAcronyms == false)
			{
				if($replacedOnceAlready || in_array($search, $noExpandAcronyms))
					continue;
			}

			if(strpos($text, $search) === false)
				continue;

			$temp = "@@@WIKIFY$i-a@@@";
			$aFinalSearch[] = $temp;
			$aFinalReplace[] = $replace;

			//$text = str_replace_once($search, $temp, $text);
			$text = preg_replace('/([^a-zA-Z0-9_-]|^)' . $search . '([^a-zA-Z0-9_-]|$)/', '\1' . $temp . '\2', $text, 1);

			 // set replaced already to true
			$gWikifyAcronyms[$i][3] = true;
		}

		// perform temp to final replace for acronyms
		$text = str_replace($aFinalSearch, $aFinalReplace, $text);
	}

	if($doLinks)
	{
		// Wikify Links
		// two-stage replace to prevent recursion
		$lFinalSearch = array(); $lFinalReplace = array();
		
		for($i=0;$i<count($gWikifyLinks);$i++)
		{
			list($unused, $search, $replace, $replaceThisOnce, $recurse, $replacedOnceAlready) = $gWikifyLinks[$i];
				
			if($search[0] != '/')
			{
				$search = escapeRegexChars($search);
				// add some regex to force finding at a word boundary
				$search = '/([^a-zA-Z0-9_-]|^)' . $search . '([^a-zA-Z0-9_-]|$)/';

				$searchIsRegex = false;
			}
			else
				$searchIsRegex = true;

			if(preg_match($search, $text) === 0 || $replacedOnceAlready)
				continue;
		
			if($searchIsRegex)
				$replace = $parser->recursiveTagParse($replace);
			else
//				$replace = escapeRegexChars($parser->recursiveTagParse($replace));

			$replace = $parser->recursiveTagParse($replace);

			if($recurse)
			{
				if(!$searchIsRegex)
					// handle side effect of word boundary regex
					$replace = '\1' . $replace . '\2';


				if($replaceOnce || $replaceThisOnce)
					$text = preg_replace($search, $replace, $text,1);
				
				else
					$text = preg_replace($search, $replace, $text);
			}
			else
			{
				// search/replace on wikify terms to a temporary to avoid recursion
				$temp = "@@@WIKIFY$i@@@";
				$lFinalSearch[] = $temp;
				$lFinalReplace[] = $replace;

				if(!$searchIsRegex)
					// handle side effect of word boundary regex
					$temp = '\1' . $temp . '\2';

				if($replaceOnce || $replaceThisOnce)
					$text = preg_replace($search, $temp, $text,1);
				else
					$text = preg_replace($search, $temp, $text);
			
			}
		
			if($replaceThisOnce)
				//$replacedOnceAlready = true;
				$gWikifyLinks[$i][5] = true;
		}
	
		// perform temporary to final replace for links
		$text = str_replace($lFinalSearch, $lFinalReplace, $text);
	}

	// perform temporary to final replace for references
	$text = str_replace($rFinalSearch, $rFinalReplace, $text);
	
	
	return $text;// . print_r("<pre>" . $text . "</pre>", true);
}

function efWikifyBeforeTidy(&$parser, &$text)
{
  global $gWikifyInputParsed, $gWikify_hasRun;
  
  // if some plain text was wikified, we don't want to see result of parsings
  if($gWikify_hasRun == true)
  {
    // strip out everything between a beginning and an ending
    $text = preg_replace('/<!-- WIKIFY_PARSE_BEGIN.+<!-- WIKIFY_PARSE_END -->/s','', $text);
  }
  
  return true;
}

?>