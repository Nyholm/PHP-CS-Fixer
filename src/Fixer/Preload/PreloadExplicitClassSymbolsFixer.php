<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\Preload;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\VersionSpecification;
use PhpCsFixer\FixerDefinition\VersionSpecificCodeSample;
use PhpCsFixer\Tokenizer\Analyzer\ArgumentsAnalyzer;
use PhpCsFixer\Tokenizer\Analyzer\FunctionsAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class PreloadExplicitClassSymbolsFixer extends AbstractFixer
{
    private $functionsAnalyzer;
    private $tokenAnalyzer;

    public function __construct()
    {
        parent::__construct();
        $this->functionsAnalyzer = new FunctionsAnalyzer();
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Adds extra `class_exists` before a class to help opcache.preload to discover always-needed symbols.',
            [
                new VersionSpecificCodeSample(
                    '<?php
use App\Foo;

class MyClass
{
    private $foo;
    public function __construct()
    {
        $this->foo = new Foo();
    }
}
',
                    new VersionSpecification(70300)
                ),
            ],
            'A script for opcache.preload should classes that are used by the average '
            .'HTTP request. A good preloading script uses reflection to load the '
            .'hot class and all classes it depends on. Reflection cannot always find '
            .'all dependencies. Example: Classes used in the constructor or dependent '
            .'classes of the constructor. This fixer will make sure we load classes '
            .'that are always used and that cannot be found by reflection. This will '
            .'improve the preload performance.'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return \PHP_VERSION_ID >= 70300 && ($tokens->isTokenKindFound(T_CLASS) || $tokens->isTokenKindFound(T_TRAIT));
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        $this->tokenAnalyzer = new TokensAnalyzer($tokens);
        $candidates = $this->parse($tokens, '__construct');

        // Remove false positives
        $candidates = array_filter($candidates, function ($candidate) {
            return !\in_array($candidate, ['parent', 'self'], true);
        });

        if (empty($candidates)) {
            return;
        }

        $candidates = array_unique($candidates);
        $classesNotToLoad = $this->getPreloadedClasses($tokens);

        $classesToLoad = array_diff($candidates, $classesNotToLoad);
        $this->injectClasses($tokens, $classesToLoad);
    }

    /**
     * Parse functions for used classes. Make a recursive call to other function.
     *
     * @param string $functionName
     *
     * @return string[] classes
     */
    private function parse(Tokens $tokens, $functionName)
    {
        if (null === $functionIndex = $this->findFunction($tokens, $functionName)) {
            return [];
        }

        $classes = [];
        $methodAttributes = $this->tokenAnalyzer->getMethodAttributes($functionIndex);

        // If not public
        if (T_PRIVATE === $methodAttributes['visibility'] || T_PROTECTED === $methodAttributes['visibility']) {
            // Get argument types
            $arguments = $this->functionsAnalyzer->getFunctionArguments($tokens, $functionIndex);
            foreach ($arguments as $argument) {
                if ($argument->hasTypeAnalysis() && !$argument->getTypeAnalysis()->isReservedType()) {
                    $classes[] = $argument->getTypeAnalysis()->getName();
                }
            }

            // Get return type
            $returnType = $this->functionsAnalyzer->getFunctionReturnType($tokens, $functionIndex);
            if (null !== $returnType && !$returnType->isReservedType()) {
                $classes[] = $returnType->getName();
            }
        }

        // Parse the body of the method
        $blockStart = $tokens->getNextTokenOfKind($functionIndex, ['{']);
        $blockEnd = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $blockStart);

        for ($i = $blockStart; $i < $blockEnd; ++$i) {
            $token = $tokens[$i];
            // Find Foo::class, new Foo() and function calls.
            if ($token->isGivenKind(T_NEW)) {
                $class = $this->getFullClassReference($tokens, $tokens->getNextMeaningfulToken($i));
                $classes[] = $class;
            } elseif ($token->isGivenKind(T_DOUBLE_COLON)) {
                $class = $this->getFullClassReference($tokens, $tokens->getPrevMeaningfulToken($i));
                $classes[] = $class;
            } elseif ($token->isGivenKind(T_OBJECT_OPERATOR)) {
                // FIXME Better check if function call to avoid false positive like "$this->bar = 2;"
                $classes = array_merge($classes, $this->parse($tokens, $tokens[$tokens->getNextMeaningfulToken($i)]->getContent()));
            }
        }

        return $classes;
    }

    /**
     * Get a class name by searching for tokens near the index given. Ie, the line might be:
     * $this->bar = new \Foo\Bar().
     *
     * If $index is representing `Bar`, then this method returns string "\Foo\Bar".
     *
     * @param null|int $index
     *
     * @return null|string
     */
    private function getFullClassReference(Tokens $tokens, $index)
    {
        if (null === $index) {
            return null;
        }

        // Find tokens that can be included in classes
        $allowed = [[T_STRING], [T_NS_SEPARATOR]];

        $first = $index - 1;
        while (isset($tokens[$first])) {
            if ($tokens[$first]->equalsAny($allowed)) {
                --$first;
            } else {
                ++$first;

                break;
            }
        }

        $last = $index + 1;
        while (isset($tokens[$last])) {
            if ($tokens[$last]->equalsAny($allowed)) {
                ++$last;
            } else {
                --$last;

                break;
            }
        }

        // Print the result
        $class = '';
        for ($i = $first; $i <= $last; ++$i) {
            $class .= $tokens[$i]->getContent();
        }

        if (empty($class)) {
            return null;
        }

        return $class;
    }

    /**
     * Get classes that are found by the preloader. Ie classes we shouldn't include in `class_exists`.
     *
     * @return string[]
     */
    private function getPreloadedClasses(Tokens $tokens)
    {
        $classes = $this->getExistingClassExists($tokens);

        // Parse public methods
        foreach ($tokens as $functionIndex => $token) {
            if (!$token->isGivenKind(T_FUNCTION)) {
                continue;
            }
            $methodAttributes = $this->tokenAnalyzer->getMethodAttributes($functionIndex);
            if (T_PUBLIC !== $methodAttributes['visibility']) {
                continue;
            }

            // Get argument types
            $arguments = $this->functionsAnalyzer->getFunctionArguments($tokens, $functionIndex);
            foreach ($arguments as $argument) {
                if ($argument->hasTypeAnalysis() && !$argument->getTypeAnalysis()->isReservedType()) {
                    $classes[] = $argument->getTypeAnalysis()->getName();
                }
            }

            // Get return type
            $returnType = $this->functionsAnalyzer->getFunctionReturnType($tokens, $functionIndex);
            if (null !== $returnType && !$returnType->isReservedType()) {
                $classes[] = $returnType->getName();
            }
        }

        // TODO parse public properties
        // TODO Get the class' interfaces and parent

        return array_unique($classes);
    }

    /**
     * Find a function in the tokens.
     *
     * @param string $name
     *
     * @return null|int the index or null. The index is to the "function" token.
     */
    private function findFunction(Tokens $tokens, $name)
    {
        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_FUNCTION)) {
                continue;
            }

            $nextTokenIndex = $tokens->getNextMeaningfulToken($index);
            $nextToken = $tokens[$nextTokenIndex];

            if ($nextToken->getContent() !== $name) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * Inject "class_exists" at the top of the file.
     */
    private function injectClasses(Tokens $tokens, array $classes)
    {
        $insertAfter = null;
        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_CLASS)) {
                continue;
            }

            $insertAfter = $tokens->getPrevMeaningfulToken($index);

            break;
        }

        if (null === $insertAfter) {
            return;
        }

        $newTokens = [];
        foreach ($classes as $class) {
            $newTokens[] = new Token([T_STRING, 'class_exists']);
            $newTokens[] = new Token('(');

            $parts = explode('\\', $class);
            $lastPart = array_pop($parts);
            foreach ($parts as $part) {
                if (!empty($part)) {
                    $newTokens[] = new Token([T_STRING, $part]);
                }
                $newTokens[] = new Token([T_NS_SEPARATOR, '\\']);
            }
            $newTokens[] = new Token([T_STRING, $lastPart]);

            $newTokens[] = new Token([T_DOUBLE_COLON, '::']);
            $newTokens[] = new Token([CT::T_CLASS_CONSTANT, 'class']);
            $newTokens[] = new Token(')');
            $newTokens[] = new Token(';');
            $newTokens[] = new Token([T_WHITESPACE, "\n"]);
        }

        $tokens->insertAt($insertAfter + 2, $newTokens);
    }

    /**
     * Get all class_exists in the beginning of the file.
     *
     * @return array
     */
    private function getExistingClassExists(Tokens $tokens)
    {
        $classes = [];
        foreach ($tokens as $index => $token) {
            if ($token->isGivenKind(T_CLASS)) {
                // Stop when a class is found
                break;
            }

            if (!$this->functionsAnalyzer->isGlobalFunctionCall($tokens, $index)) {
                continue;
            }

            if ('class_exists' === $token->getContent()) {
                $argumentsStart = $tokens->getNextTokenOfKind($index, ['(']);
                $argumentsEnd = $tokens->getNextTokenOfKind($index, [')']);
                $argumentAnalyzer = new ArgumentsAnalyzer();

                foreach ($argumentAnalyzer->getArguments($tokens, $argumentsStart, $argumentsEnd) as $start => $end) {
                    $argumentInfo = $argumentAnalyzer->getArgumentInfo($tokens, $start, $end);
                    $class = $argumentInfo->getTypeAnalysis()->getName();
                    if ('::class' === substr($class, -7)) {
                        $classes[] = substr($class, 0, -7);
                    }

                    // We are only interested in first argument
                    break;
                }
            }
        }

        return $classes;
    }
}
