// Package geonames provides reverse geocoding (lat/lon → city name) with zero
// external dependencies. It reads a compact binary database that can be built
// with the cmd/builddb tool from the GeoNames dataset.
package geonames

import (
	"encoding/binary"
	"fmt"
	"math"
	"os"
)

// RecordSize is the fixed byte size of one record in the binary database.
//
// Layout (74 bytes):
//
//	[0:4]   float32  latitude
//	[4:8]   float32  longitude
//	[8:72]  [64]byte city name (UTF-8, null-padded)
//	[72:74] [2]byte  ISO-3166-1 alpha-2 country code
const RecordSize = 74

// Record holds information about one city entry.
type Record struct {
	Lat     float64
	Lon     float64
	City    string
	Country string
}

// DB holds all city records loaded into memory for fast lookups.
type DB struct {
	records []Record
}

// Open reads the entire binary geonames database file into memory.
// All subsequent Nearest calls operate entirely in memory without any I/O.
func Open(path string) (*DB, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("geonames: open %s: %w", path, err)
	}
	if len(data)%RecordSize != 0 {
		return nil, fmt.Errorf("geonames: file size %d is not a multiple of %d", len(data), RecordSize)
	}
	n := len(data) / RecordSize
	records := make([]Record, n)
	for i := 0; i < n; i++ {
		records[i] = parseRecord(data[i*RecordSize : (i+1)*RecordSize])
	}
	return &DB{records: records}, nil
}

// Close is a no-op kept for API compatibility; the database is in memory.
func (db *DB) Close() error {
	return nil
}

// Count returns the number of records in the database.
func (db *DB) Count() int64 {
	return int64(len(db.records))
}

// Nearest returns the city record closest (Haversine distance) to the given
// lat/lon coordinates.
func (db *DB) Nearest(lat, lon float64) (Record, error) {
	if len(db.records) == 0 {
		return Record{}, fmt.Errorf("geonames: database is empty")
	}

	bestDist := math.MaxFloat64
	var best Record

	for _, rec := range db.records {
		d := haversine(lat, lon, rec.Lat, rec.Lon)
		if d < bestDist {
			bestDist = d
			best = rec
		}
	}
	return best, nil
}

// parseRecord decodes a single record from a 74-byte buffer.
func parseRecord(buf []byte) Record {
	latBits := binary.LittleEndian.Uint32(buf[0:4])
	lonBits := binary.LittleEndian.Uint32(buf[4:8])
	lat := float64(math.Float32frombits(latBits))
	lon := float64(math.Float32frombits(lonBits))
	city := nullTermString(buf[8:72])
	country := nullTermString(buf[72:74])
	return Record{Lat: lat, Lon: lon, City: city, Country: country}
}

// nullTermString converts a null-padded byte slice to a string.
func nullTermString(b []byte) string {
	for i, v := range b {
		if v == 0 {
			return string(b[:i])
		}
	}
	return string(b)
}

// haversine returns the great-circle distance in kilometres between two points.
func haversine(lat1, lon1, lat2, lon2 float64) float64 {
	const R = 6371.0
	dLat := (lat2 - lat1) * math.Pi / 180
	dLon := (lon2 - lon1) * math.Pi / 180
	a := math.Sin(dLat/2)*math.Sin(dLat/2) +
		math.Cos(lat1*math.Pi/180)*math.Cos(lat2*math.Pi/180)*
			math.Sin(dLon/2)*math.Sin(dLon/2)
	return R * 2 * math.Atan2(math.Sqrt(a), math.Sqrt(1-a))
}
