# Parser Functions

The ODBC extension provides five parser functions. They work as a pipeline: first call `#odbc_query` to fetch data, then use `#odbc_value`, `#for_odbc_table`, or `#display_odbc_table` to render it, and optionally `#odbc_clear` to reset stored data.

All parser function names are **case-insensitive** from v1.0.3 onwards — `{{#odbc_query:}}`, `{{#ODBC_QUERY:}}`, and `{{#Odbc_Query:}}` are all equivalent.

---

## `{{#odbc_query:}}` — Fetch Data

Executes a query against a configured ODBC source and stores results in page-scoped variables for use by the display functions. The results persist until `{{#odbc_clear:}}` is called or the page render completes.

### Prepared Statement Mode (recommended)

```wiki
{{#odbc_query: source=hr-db
 | query=employee_by_id
 | parameters=42
 | data=first=FirstName,last=LastName,dept=Department
}}
```

### Composed Query Mode (requires `allow_queries` or `$wgODBCAllowArbitraryQueries`)

```wiki
{{#odbc_query: source=my-source
 | from=Products
 | data=name=ProductName,price=UnitPrice,sku=SKU
 | where=Active=1 AND Category='Electronics'
 | order by=ProductName ASC
 | limit=50
}}
```

### Parameters

| Parameter | Required | Mode | Description |
|-----------|----------|------|-------------|
| `source=` | Yes | Both | The source ID key from `$wgODBCSources`. |
| `data=` | No | Both | Comma-separated `localVar=DBColumn` mappings. Stores each column's values under `localVar` for display functions. If omitted, `SELECT *` is issued and all columns are stored under their lowercase names — **this may expose sensitive columns unintentionally** (see KI-008). Using explicit `data=` mappings is strongly recommended. |
| `query=` | Prepared only | Prepared | Name of a prepared statement defined in the source's `prepared` config. Also accepted as `prepared=`. |
| `parameters=` | Conditional | Prepared | Values to bind to the `?` placeholders in the prepared statement. Multiple values are split by the `separator=` delimiter (default: `,`). |
| `separator=` | No | Prepared | Delimiter for splitting `parameters=`. Default: `,`. Use `separator=\|` or another character when parameter values contain commas (e.g. names like `Smith, John`). |
| `from=` | Composed only | Composed | `FROM` clause table name(s). Required for composed queries. |
| `where=` | No | Composed | `WHERE` clause conditions. Passed through the SQL sanitizer — dangerous patterns are blocked. |
| `order by=` | No | Composed | `ORDER BY` clause. |
| `group by=` | No | Composed | `GROUP BY` clause. |
| `having=` | No | Composed | `HAVING` clause. |
| `limit=` | No | Both | Maximum rows to return. Cannot exceed `$wgODBCMaxRows`. |
| `null_value=` | No | Both | String to substitute for database NULL values in result cells. Default: `''` (empty string, fully backward-compatible). Set to a visible sentinel (e.g. `null_value=N/A` or `null_value=—`) to distinguish NULL from an actual empty string in templates. Available since v1.5.0. |
| `suppress error` | No | Both | If present (no value needed), suppresses error messages on failure — the function returns empty output instead. |

### Data Mappings — `data=`

The `data=` parameter maps database column names to local variable names used on the page. Format: `localVar=DBColumn`, separated by commas.

```wiki
data=name=FullName,dept=DepartmentName,salary=Salary
```

- `name`, `dept`, and `salary` become the variable names used in `{{{name}}}`, `{{{dept}}}`, `{{{salary}}}` within `#for_odbc_table` or `#display_odbc_table`.
- The mapping is case-insensitive for the DB column name (i.e., `FullName` matches a column named `fullname` or `FULLNAME`).
- Mappings longer than 256 characters are silently dropped. Keep mapping names short.
- Stored values always contain all rows; `#odbc_value` returns the first row by default, or a specific row when a row selector is given (see `#odbc_value` below).

### Multi-query on One Page

You can call `#odbc_query` multiple times on the same page. Each call appends to the stored data for each variable. Call `{{#odbc_clear:}}` between logically separate queries to avoid mixing data.

### Examples

#### Prepared statement with multiple parameters
```wiki
{{#odbc_query: source=employees
 | query=search
 | parameters=Engineering,Senior%
 | data=id=EmployeeID,name=FullName,title=Title
}}
```

#### Prepared statement with comma in value — using a custom separator
```wiki
{{#odbc_query: source=employees
 | query=employee_by_name
 | parameters=Smith, John
 | separator=;
 | data=id=EmployeeID,dept=Department
}}
```

Wait — the above wouldn't work because the default separator `,` would split `Smith, John` into two values. Use:
```wiki
| parameters=Smith, John
| separator=|
```
...but note the pipe `|` must be escaped as `{{!}}` inside a template call, or use `separator=;` and separate by semicolon.

#### Composed query with WHERE and ORDER BY
```wiki
{{#odbc_query: source=public-catalogue
 | from=Categories
 | data=id=CategoryID,catname=CategoryName
 | where=Active=1
 | order by=CategoryName ASC
 | limit=20
}}
```

---

## `{{#odbc_value:}}` — Display a Single Value

Outputs the value of a stored variable for a specific row. Optionally specify a default if the variable is empty or the row is out of range.

### Syntax

```wiki
{{#odbc_value: variableName }}
{{#odbc_value: variableName | defaultValue }}
{{#odbc_value: variableName | defaultValue | rowSelector }}
```

### Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| First positional | Yes | Variable name (as defined in `data=` of `#odbc_query`). |
| Second positional | No | Default text to display if the variable has no stored value or the row is out of range. |
| Third positional (row) | No | Row selector. See below. Omit to get the first row (backward-compatible default). |

#### Row Selector

The third parameter controls which row is returned:

| Value | Meaning |
|-------|---------|
| *(omitted or empty)* | First row (default) |
| `1`, `2`, `3`, … | 1-indexed row number |
| `last` | Final row in the result set |
| `row=N` or `row=last` | Named-parameter alias for the above |

Out-of-range integers (e.g. `5` when only 3 rows exist) silently return the default value.

### Examples

```wiki
{{#odbc_query: source=hr-db | query=employee_by_id | parameters=42 | data=name=FullName,dept=Department }}

'''Name:''' {{#odbc_value: name }}
'''Department:''' {{#odbc_value: dept | (Unassigned) }}
```

```wiki
<!-- Access a specific row of a multi-row result: -->
{{#odbc_query: source=hr-db | query=top_earners | data=name=EmpName,salary=Salary }}

Highest earner: {{#odbc_value: name | (none) | 1 }}
Second highest: {{#odbc_value: name | (none) | 2 }}
Lowest of top-10: {{#odbc_value: name | (none) | last }}
```

> **Tip:** For iterating over all rows, `#for_odbc_table` or `#display_odbc_table` are more concise. Use `#odbc_value` with a row selector when you need to cherry-pick specific rows by position.

---

## `{{#for_odbc_table:}}` — Loop with Inline Wikitext

Iterates over all stored rows and renders an inline wikitext template for each. Variable values are accessed using `{{{variableName}}}` triple-brace syntax.

### Syntax

```wiki
{{#for_odbc_table:
  wikitext template using {{{variableName}}}
}}
```

### Examples

#### Basic table
```wiki
{{#odbc_query: source=products | from=Products | data=name=ProductName,price=UnitPrice | order by=ProductName }}

{| class="wikitable"
! Product !! Price
{{#for_odbc_table:
{{!}}-
{{!}} {{{name}}} {{!}}{{!}} ${{{price}}}
}}
|}

{{#odbc_clear:}}
```

#### List
```wiki
{{#odbc_query: source=hr-db | query=dept_roster | parameters=Engineering | data=name=FullName,email=Email }}

* {{#for_odbc_table: [[User:{{{name}}}|{{{name}}}]] &lt;{{{email}}}&gt; }}

{{#odbc_clear:}}
```

> **Tip:** Use `{{!}}` to produce a literal pipe `|` inside `#for_odbc_table`, since `|` is a template parameter separator in MediaWiki wikitext.

---

## `{{#display_odbc_table:}}` — Loop with a Wiki Template

Iterates over all stored rows and calls a wiki template for each row, passing all stored variables as named parameters. This separates the data query from the display logic.

### Syntax

```wiki
{{#display_odbc_table: template=TemplateName }}
```

### Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `template=` | Yes | Name of a wiki template in the `Template:` namespace. Called once per row with all stored variables as named parameters. |

### How it Works

For each row in the stored result, the extension calls:
```wiki
{{TemplateName | localVar1=value1 | localVar2=value2 | ... }}
```

The template at `Template:TemplateName` accesses values with `{{{localVar1}}}`, `{{{localVar2}}}`, etc.

### Example

**LocalSettings.php:**
```php
$wgODBCSources['products'] = [
    'driver' => 'MySQL ODBC 8.0 Unicode Driver',
    // ...
    'prepared' => [
        'active_products' => 'SELECT ProductName, UnitPrice, CategoryName FROM vw_Products WHERE Active = 1',
    ],
];
```

**Template:ProductCard** (`Template:ProductCard`):
```wiki
<div class="product-card">
'''[[Product:{{{name}}}|{{{name}}}]]'''
Price: ${{{price}}}
Category: {{{cat}}}
</div>
```

**Wiki page:**
```wiki
{{#odbc_query: source=products
 | query=active_products
 | data=name=ProductName,price=UnitPrice,cat=CategoryName
}}

{{#display_odbc_table: template=ProductCard }}

{{#odbc_clear:}}
```

---

## `{{#odbc_clear:}}` — Clear Stored Data

Clears variables stored by previous `#odbc_query` calls.

### Syntax

```wiki
{{#odbc_clear:}}               <!-- Clear ALL stored variables -->
{{#odbc_clear: var1,var2 }}    <!-- Clear specific variables only -->
```

### Parameters

| Parameter | Description |
|-----------|-------------|
| Comma-separated list (optional) | Variable names to clear. If omitted, all stored data is cleared. |

### When to Use

- **Always** call `{{#odbc_clear:}}` after rendering a result set before running a second `#odbc_query` on the same page. Without clearing, old and new data may merge.
- When only displaying one result set per page, the clear is optional (data is automatically discarded at the end of the page render), but it is good practice.

### Example

```wiki
== Section 1: Active Products ==

{{#odbc_query: source=products | query=active_products | data=name=ProductName,price=UnitPrice }}
{{#for_odbc_table: * {{{name}}} — ${{{price}}} }}
{{#odbc_clear:}}

== Section 2: Discontinued Products ==

{{#odbc_query: source=products | query=discontinued | data=name=ProductName,price=UnitPrice }}
{{#for_odbc_table: * <s>{{{name}}}</s> }}
{{#odbc_clear:}}
```

---

## Full Worked Example

### Goal: Show an employee directory with a search result

**LocalSettings.php:**
```php
$wgODBCSources['hr'] = [
    'driver'   => 'ODBC Driver 17 for SQL Server',
    'server'   => 'hr-server,1433',
    'database' => 'HR',
    'user'     => 'wiki_hr_ro',
    'password' => 'readonly!',
    'prepared' => [
        'all_employees'   => 'SELECT FirstName, LastName, Department, Email FROM Employees ORDER BY LastName, FirstName',
        'dept_employees'  => 'SELECT FirstName, LastName, Department, Email FROM Employees WHERE Department = ? ORDER BY LastName',
    ],
];
```

**Template:EmployeeRow** (`Template:EmployeeRow`):
```wiki
|-
| {{{FirstName}}} {{{LastName}}} || {{{Department}}} || [{{{Email}}} email]
```

**Wiki page:**
```wiki
== All Employees ==

{{#odbc_query: source=hr
 | query=all_employees
 | data=FirstName=FirstName,LastName=LastName,Department=Department,Email=Email
}}

{| class="wikitable sortable"
! First Name !! Last Name !! Department !! Contact
{{#display_odbc_table: template=EmployeeRow }}
|}

{{#odbc_clear:}}

== Engineering Team ==

{{#odbc_query: source=hr
 | query=dept_employees
 | parameters=Engineering
 | data=FirstName=FirstName,LastName=LastName,Department=Department,Email=Email
}}

Total engineers: '''{{#odbc_value: FirstName | (none)}}'''

{| class="wikitable"
! Name !! Email
{{#for_odbc_table:
{{!}}-
{{!}} {{{FirstName}}} {{{LastName}}} {{!}}{{!}} {{{Email}}}
}}
|}

{{#odbc_clear:}}
```
