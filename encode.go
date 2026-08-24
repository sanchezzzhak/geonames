package geonames

import (
	"encoding/binary"
	"math"
)

// EncodeRecord serialises a Record into the 74-byte binary format.
// The returned slice is always exactly RecordSize bytes.
func EncodeRecord(r Record) []byte {
	buf := make([]byte, RecordSize)
	binary.LittleEndian.PutUint32(buf[0:4], math.Float32bits(float32(r.Lat)))
	binary.LittleEndian.PutUint32(buf[4:8], math.Float32bits(float32(r.Lon)))
	city := []byte(r.City)
	if len(city) > 64 {
		city = city[:64]
	}
	copy(buf[8:72], city)
	cc := []byte(r.Country)
	if len(cc) > 2 {
		cc = cc[:2]
	}
	copy(buf[72:74], cc)
	return buf
}
