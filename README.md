# BPP
Better preprocessor, inspired by the C/C++ preprocessor, and taken a few steps further.

## Background
As a C++ developer by heart, the idea to this project came out of pure frustration over how "almost there, but not quite" C++ is. The problems become evident when you try to implement features not built into the language, like reflection (as in Java).

I realized that the C/C++ preprocessor actually is, together with C++ templates, a limited scripting language that runs in compile time, to generate code. BPP's aim is to become a turing complete and more potent such code generation language.

It's currently implemented in PHP for ease of implementation and portability.

## How it works
BPP is a state machine. It starts with an "empty" set of rules (except some standard built-in rules), which can later be extended with new rules. It reads one line at a time. A line can be extended with a backslash just before the line break (i.e. "line continuation"), just like C++. When it reaches the end of the file, or one of the predefined preprocessor directives, it will apply all currently known rules to the text from the last directive or the beginning of the file. With other words, it applies rules to text in between two preprocessor directives.

A rule is a pattern to search for, and some text to replace it with. So it's basically a search-and-replace algorithm.

All rules, including the predefined preprocessor directives listed above, are replaced with some other text. The resulting text, after processing a whole file, is the final output of the program.

#### A word about infinite loops and rule matching
When a rule matches some text, and that text is replaced with something else, the preprocessor continues to match rules against the new output from the same position as that first match. This means that if one rule matches its own output, an infinite loop would occur. Because of that, BPP doesn't allow one rule to match the same position twice. This doesn't prevent you from writing a bad set of rules, but it's at least some level of protection.

#### Identifiers
An *identifier* is a string that begins with a letter or an underscore, and only contains underscores, letters or digits. In C and C++, identifiers can be variable names, keywords or type names. It is a word to remember since it will be used further down.

When matching an identifier it will only match tokens that are *NOT* preceded or followed by characters that are allowed within an identifier. For instance, "TEST" will not match "THIS_IS_A_TEST", but it will match "$TEST!".

## Predefined preprocessor directives
All predefined preprocessor directives are, after parsing them, replaced with the empty string in the output (i.e. removed). Their sole purpose is to update the state of the preprocessor.

* #include
* #define
    - Constant
    - Macro
    - Block
    - Regular expression
    - Composite
* #ifdef
* #ifndef
* #endif
* #context

### #include
This opens a file and recursively processes it.

Usage: 

`#include "path/to/file"`

### #define
This defines a rule, i.e. a pattern to match, and something to replace it with. There are five kinds of rules that you can define:

1. Constants
2. Macros
3. Blocks
4. Regular expressions
5. Composite rules

All rules are identified by their *signature*. What denotes the signature is described for each type below.

### #define CONSTANT
This is the simplest kind of rule that you can use:

`#define TEXT_TO_FIND *replacement*`

The string TEXT_TO_FIND is the rule's signature and name, and can only contain characters as described in the section about identifiers above. The *replacement* is everything to the right of the name, until the end of the line.

### #define MACRO
Macros look like functions, with a name, parantheses and parameters:

`#define SOME_MACRO(A, B, C) *replacement*`

While the string SOME_MACRO is the rule's name (and an identifier), the rule's *signature* is the name including the parantheses and the parameters, i.e. "SOME_MACRO(A, B, C)". They work in about the same way as macros in C++.

The example above will only match when given exactly three arguments, like this:

`SOME_MACRO(Hello, World, !)`

### #define MACRO variadic

The operator '...' can replace the last parameter's name, and allows for a variable number of arguments:

`#define SOME_MACRO(A, B, ...) *replacement*`

This matches three arguments or more, but not two. You can get the value of '...' by the magic constant \_\_VA_ARGS\_\_. You can also get the number of arguments given in the place of '...' by \_\_C_ARGS\_\_.

**Example**, using recursion:

`#define PUT(A) A // Terminating condition`

`#define PUT(A, ...) A PUT(__VA_ARGS__) // Recursive`

`#define COUNT(...) __C_ARGS__`

`PUT(Hello, World, !)`

`COUNT(Hello, World, !)`

The above will output:

`Hello World !`

`3`

As mentioned under "A word about infinite loops and rule matching", you cannot use recursion if the rule matches its own output like this:

`#define PUT(A, ...) PUT(__VA_ARGS__) A`

You can, however, circumvent this by placing a dummy before the recursion:

`#define DUMMY`
`#define PUT(A, ...) DUMMY PUT(__VA_ARGS__) A`

With recursion you can create loops, in the spirit of functional programming. And since there is no stack (the rules are simply matched over and over again), there is no limit to the recursion depth. I.e. it can keep looping forever, until you run out of memory.

### #define MACRO conditional
You can define macros that will only match if some condition is satisfied. You can match actual values:

`#define IF_POSITIVE(X >= 0) *replacement*`

You can match other, previously defined constants or macros:
`#define IF_EQUAL_TO_FOO(X == FOO) *replacement*`

You can also make comparisons between arguments within the same macro:
`#define GREATER_THAN(A > B, B) *replacement*`

The comparisons are made by the logic of PHP. Since everything are *tokens* to the preprocessor, the comparisons are mere string comparisons. But as PHP works, if two strings can be interpreted as, let's say integers, they will be compares as such. The same applies to floating point values.

Since the *signature* of the macro includes the conditions, you can overload the same macro twice to get the effect of if/else statements:

`#define GREATER_THAN(A > B, B) *replacement if true*`
`#define GREATER_THAN(A <= B, B) *replacement if false*`

This is, again, in the spirit of functional programming (a bit inspired by Haskell and its "guards").

### #define BLOCK
Block definitions look like nameless macros, with two parameters:

`#define ((, )) *replacement*`
`#define (/*, */) *replacement*`
`#define ({, }) *replacement*`

A common pattern in computer languages is *recursive blocks*. A general description of a *block* is something that begins with some pattern, ends with another pattern, and can contain such blocks within itself.

Both in math as well as in *expressions* in programming languages, parantheses are used to create such blocks:

`y = ((x + 1) * 2 + 3) * 4`

In this case, the block begins with a '(' and ends with a ')'. Another example is recursive multiline comments, starting with '/\*' and ending with '\*/':

`/*`
`    This is a multiline comment.`
`    /* This is a comment within a comment. */`
`*/`

Scopes (starting with '{' and ending with '}'):

`void f(int x) {`
`    for (int i = 0; i < x; ++i) {`
`        // ...`
`    }`
`}`

Note: A *block* rule will only match the outermost block. Not the inner.

When defining a *block* rule, the contents of the entire block, including the delimiters, can be accessed through the magic variable \_\_BLOCK\_\_. 

### #define REGEX
If you know regular expressions, this is pretty straight forward:

`#define /My (regular) (expression)/ *replacement*`

You can access each match within the regex by index, prefixed with a '#'.

`#define /class ([a-zA-Z_][a-zA-Z_0-9]*)/ I found a class called #1`

### #define COMPOSITE
Regular expressions are great as long as you can keep them small and simple. But for more complex tasks, they tend to become indecipherable and difficult to wrap your head around. *Composite* rules let you chain one rule after another, like a logical AND expression:

`#define [class, /[a-zA-Z_][a-zA-Z_0-9]*/, ({, })] *replacement*`

In this example, we have composed one rule of three other rules inside brackets. This expression will match a class definition.

The first rule is a simple constant "class". The second is a regular expression matching the class name. The third is a block, matching the body of the class.

Inside the body (the *replacement*) of a composite rule, you can access the match of each of the subrules by indexing, as in regular expressions. However, a single index only makes a reference to the rule, not the contents of the match. So to get the class name, and the block, you can do so as follows:

`#1[#0] // Rule at index 1 is the regular expression. Match with index 0 is the full match, i.e. the class name.`

`#2[__BLOCK__] // Rule at index 2 is the block, and we access its contents through the magic variable __BLOCK__.`

