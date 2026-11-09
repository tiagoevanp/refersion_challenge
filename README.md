# Challenge Explanations

## Project Construction

List of the stack:

- Laravel 13
- SQLite databse
- React 19 with TypeScript
- Tailwind CSS v4
- shadcn/ui
- Vite+
- PHPUnit 12 with Mockery and Faker
- Pint (code formatting)
- Laravel Boost (MCP server), Paul and Tinker

The project was build using AI tools like Claude Code. The AI model, tools and prompts used are documented inside of ai_usage.mkd in the root of the project.

**Each AI step is verified by the author of the project avoiding errors or unexpected behaviors (Example: Hallucinations).**

## Next Steps

- Table content is not working as a real-time information, it relies on a first page load (refresh of the page, update content).
- Check if the currency used in website presentation is accurate. Assuming for now that the currency is in USD cents.
- Cache strategies could cause better results in the affiliate API calls (removing unnecessary refetch of custom fields for example). For now it's fine for the 44 affiliates found.
