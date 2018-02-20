<?php
/**
 * Created by scottshipman
 * Date: 2/20/18
 *
 * Extends Mautic\CoreBundle\Helper\SearchStringHelper
 *
 * to interpret Argument filters for lead filters aware of ExtendedFields
 *
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Helper;

use Mautic\CoreBundle\Helper\SearchStringHelper;

class ExtendedSearchStringHelper extends SearchStringHelper
{

  /**
   * @param string $input
   * @param string $baseName
   * @param string $overrideCommand
   *
   * @return \stdClass
   */
  protected function splitUpSearchString($input, $baseName = 'root', $overrideCommand = '')
  {
    $keyCount                                 = 0;
    $command                                  = $overrideCommand;
    $filters                                  = new \stdClass();
    $filters->commands                        = [];
    $filters->{$baseName}                     = [];
    $filters->{$baseName}[$keyCount]          = new \stdClass();
    $filters->{$baseName}[$keyCount]->type    = 'and';
    $filters->{$baseName}[$keyCount]->command = $command;
    $filters->{$baseName}[$keyCount]->string  = '';
    $filters->{$baseName}[$keyCount]->not     = 0;
    $filters->{$baseName}[$keyCount]->strict  = 0;
    $chars                                    = str_split($input);
    $pos                                      = 0;
    $string                                   = '';

    //Iterate through every character to ensure that the search string is properly parsed from left to right while
    //considering quotes, parenthesis, and commands
    while (count($chars)) {
      $char = $chars[$pos];

      $string .= $char;
      unset($chars[$pos]);
      ++$pos;

      if ($char == ':') {
        //the string is a command
        $command = trim(substr($string, 0, -1));
        //does this have a negative?
        if (strpos($command, '!') === 0) {
          $filters->{$baseName}[$keyCount]->not = 1;
          $command                              = substr($command, 1);
        }

        if (empty($chars)) {
          // Command hasn't been defined so don't allow empty or could end up searching entire table
          unset($filters->{$baseName}[$keyCount]);
        } else {
          $filters->{$baseName}[$keyCount]->command = $command;
          $string                                   = '';
        }
      } elseif ($char == ' ') {
        //arrived at the end of a single word that is not within a quote or parenthesis so add it as standalone
        if ($string != ' ') {
          $string = trim($string);
          $type   = (strtolower($string) == 'or' || strtolower($string) == 'and') ? $string : '';
          $this->setFilter($filters, $baseName, $keyCount, $string, $command, $overrideCommand, true, $type, (!empty($chars)));
        }
        continue;
      } elseif (in_array($char, $this->needsClosing)) {
        //arrived at a character that has a closing partner and thus needs to be parsed as a group

        //find the closing match
        $key = array_search($char, $this->needsClosing);

        $openingCount = 1;
        $closingCount = 1;

        //reiterate through the rest of the chars to find its closing match
        foreach ($chars as $k => $c) {
          $string .= $c;
          unset($chars[$k]);
          ++$pos;

          if ($c === $this->closingChars[$key] && $openingCount === $closingCount) {
            //found the matching character (accounts for nesting)

            //remove wrapping grouping chars
            if (strpos($string, $char) === 0 && substr($string, -1) === $c) {
              $string = substr($string, 1, -1);
            }

            //handle characters that support nesting
            $neededParsing = false;
            if ($c !== '"') {
              //check to see if the nested string needs to be parsed as well
              foreach ($this->needsParsing as $parseMe) {
                if (strpos($string, $parseMe) !== false) {
                  $parsed                                    = $this->splitUpSearchString($string, 'parsed', $command);
                  $filters->{$baseName}[$keyCount]->children = $parsed->parsed;
                  $neededParsing                             = true;
                  break;
                }
              }
            }

            $this->setFilter($filters, $baseName, $keyCount, $string, $command, $overrideCommand, (!$neededParsing));

            break;
          } elseif ($c === $char) {
            //this is another opening char so keep track of it to properly handle nested strings
            ++$openingCount;
          } elseif ($c === $this->closingChars[$key]) {
            //this is a closing char within a nest but not the one to close the group
            ++$closingCount;
          }
        }
      } elseif (empty($chars)) {
        $filters->{$baseName}[$keyCount]->command = $command;
        $this->setFilter($filters, $baseName, $keyCount, $string, $command, $overrideCommand, true, null, false);
      }//else keep concocting chars
    }

    return $filters;
  }


}
