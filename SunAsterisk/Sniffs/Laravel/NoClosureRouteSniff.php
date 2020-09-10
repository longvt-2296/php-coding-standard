<?php

namespace SunAsterisk\Sniffs\Laravel;

use Exception;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

class NoClosureRouteSniff implements Sniff
{
    private static $routeDefiningMethods = [
        'get',
        'post',
        'put',
        'patch',
        'delete',
        'options',
        'any',
        'match',
    ];

    private static $bracketOpeners = [
        T_OPEN_PARENTHESIS,
        T_OPEN_CURLY_BRACKET,
        T_OPEN_SQUARE_BRACKET,
        T_OPEN_SHORT_ARRAY,
    ];

    private static $bracketOpenerClosers = [
        T_OPEN_PARENTHESIS => 'parenthesis_closer',
        T_OPEN_CURLY_BRACKET => 'bracket_closer',
        T_OPEN_SQUARE_BRACKET => 'bracket_closer',
        T_OPEN_SHORT_ARRAY => 'bracket_closer',
    ];

    public function register(): array
    {
        return [
            T_DOUBLE_COLON,
        ];
    }

    /**
     * @param File $phpcsFile The file being scanned
     * @param int  $stackPtr Current token position
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        $prevToken = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, null, true);
        if ($tokens[$prevToken]['content'] !== 'Route') {
            // Not a call to Route
            return;
        }

        $routeDefiningMethod = $this->findRouteDefiningMethod($phpcsFile, $stackPtr);
        if (!$routeDefiningMethod) {
            // Could not find a route defining method
            return;
        }

        $openParenthesis = $phpcsFile->findNext(Tokens::$emptyTokens, $routeDefiningMethod + 1, null, true);
        if (!$this->isValidParenthesesPair($phpcsFile, $openParenthesis)) {
            // Not a function call
            return;
        }

        // Route::match's route handler is the 3rd argument
        $routeHandlerArgumentPosition = $tokens[$routeDefiningMethod]['content'] === 'match' ? 3 : 2;

        try {
            $handler = $this->findFunctionArgument($phpcsFile, $openParenthesis, $routeHandlerArgumentPosition);
            // Could not find the handler
            if (!$handler) {
                return;
            }
        } catch (Exception $e) {
            return;
        }

        if ($tokens[$handler]['code'] === T_CLOSURE) {
            $phpcsFile->addError('Closure route handler is forbidden', $handler, 'Found');
        }
    }

    /**
     * Get pointer to the route defining method
     *
     * @param  File $phpcsFile The file being scanned
     * @param  int $start The starting position of the Route call
     * @return int|false
     */
    private function findRouteDefiningMethod(File $phpcsFile, int $start)
    {
        $endOfStatement = $phpcsFile->findEndOfStatement($start);
        $found = $phpcsFile->findNext(Tokens::$functionNameTokens, $start + 1, $endOfStatement);

        while ($found > 0 && !$this->isRouteDefiningMethod($phpcsFile, $found)) {
            $found = $phpcsFile->findNext(Tokens::$functionNameTokens, $found + 1, $endOfStatement);
        }

        return $found;
    }

    /**
     * Get pointer to argument of a function call
     *
     * @param  File $phpcsFile The file being scanned
     * @param  int  $openParenthesis The opening parenthesis pointer
     * @param  int  $position The position of the argument to find
     * @return int|false
     */
    private function findFunctionArgument(File $phpcsFile, int $openParenthesis, int $position = 1)
    {
        $tokens = $phpcsFile->getTokens();

        $closer = $tokens[$openParenthesis]['parenthesis_closer'];

        $remaining = $position - 1;
        $ptr = $openParenthesis + 1;

        while ($ptr < $closer && $remaining > 0) {
            if (in_array($tokens[$ptr]['code'], static::$bracketOpeners)) {
                // Skip to end of bracket
                $ptr = $this->getBracketCloser($phpcsFile, $ptr) + 1;

                continue;
            }

            if ($tokens[$ptr]['code'] === T_COMMA) {
                $remaining -= 1;
            }

            $ptr += 1;
        }

        if ($remaining !== 0) {
            $found = $position - $remaining;

            throw new Exception("Function call only has {$found} arguments, trying to find argument {$position}");
        }

        return $phpcsFile->findNext(Tokens::$emptyTokens, $ptr, null, true);
    }

    private function isRouteDefiningMethod(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();

        return in_array($tokens[$stackPtr]['content'], static::$routeDefiningMethods);
    }

    private function isValidParenthesesPair(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();

        return $tokens[$stackPtr]['code'] === T_OPEN_PARENTHESIS && isset($tokens[$stackPtr]['parenthesis_closer']);
    }

    private function getBracketCloser(File $phpcsFile, int $stackPtr): int
    {
        $token = $phpcsFile->getTokens()[$stackPtr];
        $tokenCode = $token['code'];

        if (!isset(static::$bracketOpeners, $tokenCode)) {
            throw new Exception("{$tokenCode} is not a bracket opener");
        }

        $closerKey = static::$bracketOpenerClosers[$tokenCode];

        if (!isset($token, $closerKey)) {
            throw new Exception('Missing closing bracket');
        }

        return $token[$closerKey];
    }
}
