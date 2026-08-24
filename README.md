# geonames

Get city name by latitude and longitude coordinates.  
Zero external dependencies. Uses a compact binary database for fast lookups.

## Quick start

### 1. Build the binary database

```bash
# Download cities500.txt from geonames.org automatically and build the DB
go run ./cmd/builddb -output cities500.bin

# Or supply an existing cities500.txt
go run ./cmd/builddb -input cities500.txt -output cities500.bin
```

### 2. Use the library

```go
package main

import (
    "fmt"
    "log"

    "github.com/sanchezzzhak/geonames"
)

func main() {
    db, err := geonames.Open("cities500.bin")
    if err != nil {
        log.Fatal(err)
    }
    defer db.Close()

    // Coordinates for Paris, France
    rec, err := db.Nearest(48.8566, 2.3522)
    if err != nil {
        log.Fatal(err)
    }

    fmt.Printf("City: %s (%s)\n", rec.City, rec.Country)
    // Output: City: Paris (FR)
}
```

## Database format

Each record is exactly **74 bytes** (little-endian):

| Bytes | Type       | Description                    |
|-------|------------|--------------------------------|
| 0–3   | float32    | Latitude                       |
| 4–7   | float32    | Longitude                      |
| 8–71  | [64]byte   | City name (UTF-8, null-padded) |
| 72–73 | [2]byte    | ISO 3166-1 alpha-2 country code|

## Data source

Cities with population ≥ 500 from [GeoNames](https://www.geonames.org/)
(`cities500.txt`), licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
