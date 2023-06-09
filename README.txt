Code Refactoring

The previous code was functional, but it required certain modifications to improve its overall quality. The following changes were made:

-> Implemented exception handling using the try and catch mechanism to handle potential errors gracefully.
-> Enforced appropriate data types such as integers and arrays for improved type safety.
-> Defined return types for functions to provide clarity on the expected output.
-> Corrected indentation and spacing for consistent code formatting.
-> Replaced the usage of cURL with Guzzle HTTP, a more modern and feature-rich HTTP client library.
-> Added descriptive comments for function descriptions, making it easier to understand their purpose and behavior.
-> Introduced comprehensive commenting within each function to facilitate comprehension of the logic and functionality.
-> Restructured certain sections of the code to simplify its implementation.
-> Utilized the empty() function instead of checking for isset() and == '' to validate required fields.
-> Employed meaningful variable names to enhance code readability.
-> Eliminated unnecessary intermediate variables, leading to a more streamlined code structure.


Test Cases

Outlined below are the test cases for the two functions, willExpireAt and createOrUpdate:

Test Cases for willExpireAt:

Verify the expiration date after a duration of 90 hours.
Validate the expiration date before a duration of 90 hours.
Check the expiration date after a duration of 72 hours etc.

Test Cases for createOrUpdate:

Create new data using factories and verify the success of the operation.
Update existing data using factories and validate the success of the operation.
The functions have been appropriately commented to facilitate understanding of their underlying logic.
