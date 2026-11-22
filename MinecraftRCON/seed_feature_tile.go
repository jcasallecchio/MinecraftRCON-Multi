package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"os"
	"strconv"
	"strings"

	"github.com/helix-bedrock/helix-data/data"
	"github.com/helix-bedrock/worldgen/blocks"
	"github.com/helix-bedrock/worldgen/overworld"
)

const featureTileSize = 2048

type blockTypeMetadata struct {
	Default uint32   `json:"defaultBlockStateHash"`
	Tags    []string `json:"tags"`
}

type featurePoint struct {
	Type string `json:"type"`
	Name string `json:"name"`
	X    int    `json:"x"`
	Y    int    `json:"y"`
	Z    int    `json:"z"`
}

type featureCell struct{ X, Z int }

type featureCollector struct {
	points []featurePoint
	cells  map[string]map[featureCell][]featurePoint
}

func (c *featureCollector) add(point featurePoint, distance int) {
	if c.cells == nil {
		c.cells = make(map[string]map[featureCell][]featurePoint)
	}
	key := point.Type + "\x00" + point.Name
	byType := c.cells[key]
	if byType == nil {
		byType = make(map[featureCell][]featurePoint)
		c.cells[key] = byType
	}
	cell := featureCell{point.X / distance, point.Z / distance}
	for x := cell.X - 1; x <= cell.X+1; x++ {
		for z := cell.Z - 1; z <= cell.Z+1; z++ {
			for _, existing := range byType[featureCell{x, z}] {
				dx, dz := existing.X-point.X, existing.Z-point.Z
				if dx*dx+dz*dz < distance*distance {
					return
				}
			}
		}
	}
	byType[cell] = append(byType[cell], point)
	c.points = append(c.points, point)
}

func loadFeatureRegistry() (*blocks.Registry, map[string]uint32, error) {
	states, err := data.BlockStates()
	if err != nil {
		return nil, nil, err
	}
	typesFile, err := os.Open("/usr/share/minedev-seed/block_types.json")
	if err != nil {
		return nil, nil, err
	}
	defer typesFile.Close()
	var types map[string]blockTypeMetadata
	if err := json.NewDecoder(typesFile).Decode(&types); err != nil {
		return nil, nil, err
	}
	vanillaStates := make([]blocks.State, len(states))
	indicesByHash := make(map[uint32]uint32, len(states))
	for i, state := range states {
		vanillaStates[i] = blocks.State{Name: state.Name, Props: state.Properties}
		indicesByHash[blocks.Hash(vanillaStates[i])] = uint32(i)
	}
	defaults := make(map[string]uint32, len(types))
	tags := make(map[string][]string, len(types))
	for name, typ := range types {
		index, ok := indicesByHash[typ.Default]
		if !ok {
			return nil, nil, fmt.Errorf("estado padrão ausente para %s", name)
		}
		defaults[name] = index
		if len(typ.Tags) > 0 {
			tags[name] = typ.Tags
		}
	}
	properties, err := data.AllBlockProperties()
	if err != nil {
		return nil, nil, err
	}
	translucency, err := data.BlockTranslucency()
	if err != nil {
		return nil, nil, err
	}
	light := make([]uint8, len(vanillaStates))
	blockTranslucency := make([]float32, len(vanillaStates))
	stateOrder := make(map[string]int)
	for i, state := range states {
		order := stateOrder[state.Name]
		stateOrder[state.Name] = order + 1
		light[i] = properties[state.Name].LightDampening.At(order)
		blockTranslucency[i] = translucency[state.Name]
	}
	reg, err := blocks.New(blocks.Source{States: vanillaStates, Defaults: defaults, Tags: tags, Light: light, Translucency: blockTranslucency})
	if err != nil {
		return nil, nil, err
	}
	biomes, err := data.BiomeIDs()
	if err != nil {
		return nil, nil, err
	}
	biomeIDs := make(map[string]uint32, len(biomes))
	for name, id := range biomes {
		if id < 0 {
			return nil, nil, fmt.Errorf("ID de bioma inválido: %s", name)
		}
		biomeIDs[strings.TrimPrefix(name, "minecraft:")] = uint32(id)
	}
	return reg, biomeIDs, nil
}

func scanFeatureChunk(boneBlock, rawIron, rawCopper, air, caveAir, water, lava uint32, chunk *overworld.Chunk, chunkX, chunkZ int32, tileMinX, tileMinZ int, features *featureCollector) {
	minX, minZ := int(chunkX)*16, int(chunkZ)*16
	var chunkOres [2]*featurePoint
	var fossil *featurePoint
	for y := overworld.MinY; y < 64; y++ {
		for z := 0; z < 16; z++ {
			for x := 0; x < 16; x++ {
				blockID := chunk.Block(x, y, z)
				wx, wz := minX+x, minZ+z
				if wx < tileMinX || wx >= tileMinX+featureTileSize || wz < tileMinZ || wz >= tileMinZ+featureTileSize {
					continue
				}
				switch blockID {
				case boneBlock:
					if fossil == nil {
						fossil = &featurePoint{Type: "fossil", Name: "Fóssil", X: wx, Y: y, Z: wz}
					}
				case rawIron:
					if chunkOres[0] == nil {
						chunkOres[0] = &featurePoint{Type: "ore_vein", Name: "Veio de ferro", X: wx, Y: y, Z: wz}
					}
				case rawCopper:
					if chunkOres[1] == nil {
						chunkOres[1] = &featurePoint{Type: "ore_vein", Name: "Veio de cobre", X: wx, Y: y, Z: wz}
					}
				}
			}
		}
	}
	if fossil != nil {
		features.add(*fossil, 24)
	}
	for _, vein := range chunkOres {
		if vein != nil {
			features.add(*vein, 16)
		}
	}

	// A cave marker represents an underground air pocket in this chunk. The
	// point is placed at a sampled cavity voxel, avoiding surface air and water.
	for y := overworld.MinY + 5; y < 48; y += 4 {
		found := false
		for z := 0; z < 16 && !found; z += 2 {
			for x := 0; x < 16; x += 2 {
				wx, wz := minX+x, minZ+z
				if wx < tileMinX || wx >= tileMinX+featureTileSize || wz < tileMinZ || wz >= tileMinZ+featureTileSize {
					continue
				}
				blockID := chunk.Block(x, y, z)
				if blockID != air && blockID != caveAir {
					continue
				}
				// Require surrounding solid blocks so a tall ravine or open
				// surface does not turn into a cloud of cave markers.
				solid := 0
				for _, offset := range [][3]int{{1, 0, 0}, {-1, 0, 0}, {0, 0, 1}, {0, 0, -1}, {0, 1, 0}, {0, -1, 0}} {
					bx, by, bz := x+offset[0], y+offset[1], z+offset[2]
					if bx < 0 || bx > 15 || bz < 0 || bz > 15 || by <= overworld.MinY || by >= overworld.MaxY {
						continue
					}
					neighbor := chunk.Block(bx, by, bz)
					if neighbor != air && neighbor != caveAir && neighbor != water && neighbor != lava {
						solid++
					}
				}
				if solid >= 3 {
					features.add(featurePoint{Type: "cave", Name: "Caverna", X: wx, Y: y, Z: wz}, 24)
					found = true
					break
				}
			}
		}
		if found {
			break
		}
	}
}

func generateFeatureTile(seed int64, tileX, tileZ int, output string) error {
	if tileX < -16 || tileX > 15 || tileZ < -16 || tileZ > 15 {
		return errors.New("setor fora da área suportada")
	}
	reg, biomeIDs, err := loadFeatureRegistry()
	if err != nil {
		return err
	}
	var boneBlock, rawIron, rawCopper, air, caveAir, water, lava uint32
	if err := reg.Resolve(
		blocks.Want{Dst: &boneBlock, Name: "minecraft:bone_block"},
		blocks.Want{Dst: &rawIron, Name: "minecraft:raw_iron_block"},
		blocks.Want{Dst: &rawCopper, Name: "minecraft:raw_copper_block"},
		blocks.Want{Dst: &air, Name: "minecraft:air"},
		blocks.Want{Dst: &caveAir, Name: "minecraft:cave_air"},
		blocks.Want{Dst: &water, Name: "minecraft:water"},
		blocks.Want{Dst: &lava, Name: "minecraft:lava"},
	); err != nil {
		return err
	}
	cfg := overworld.Vanilla()
	cfg.Skip.Structures = true
	gen, err := overworld.NewOverworld(seed, reg, biomeIDs, overworld.WithConfig(cfg))
	if err != nil {
		return err
	}
	cache := overworld.NewTerrainCache(gen, 64)
	minX, minZ := tileX*featureTileSize, tileZ*featureTileSize
	chunkX0, chunkZ0 := int32(math.Floor(float64(minX) / 16)), int32(math.Floor(float64(minZ) / 16))
	features := featureCollector{points: make([]featurePoint, 0, 256)}
	var chunk overworld.Chunk
	for cz := chunkZ0; cz < chunkZ0+featureTileSize/16; cz++ {
		for cx := chunkX0; cx < chunkX0+featureTileSize/16; cx++ {
			cache.Generate(cx, cz, &chunk)
			scanFeatureChunk(boneBlock, rawIron, rawCopper, air, caveAir, water, lava, &chunk, cx, cz, minX, minZ, &features)
		}
	}
	file, err := os.Create(output)
	if err != nil {
		return err
	}
	encoder := json.NewEncoder(file)
	if err := encoder.Encode(features.points); err != nil {
		file.Close()
		return err
	}
	return file.Close()
}

func main() {
	if len(os.Args) != 5 {
		fmt.Fprintln(os.Stderr, "usage: minedev-seed-features SEED TILE_X TILE_Z OUTPUT_JSON")
		os.Exit(2)
	}
	seed, err := strconv.ParseInt(os.Args[1], 10, 64)
	if err != nil {
		fmt.Fprintln(os.Stderr, "seed inválida:", err)
		os.Exit(2)
	}
	tileX, errX := strconv.Atoi(os.Args[2])
	tileZ, errZ := strconv.Atoi(os.Args[3])
	if errX != nil || errZ != nil {
		fmt.Fprintln(os.Stderr, "coordenadas de setor inválidas")
		os.Exit(2)
	}
	if err := generateFeatureTile(seed, tileX, tileZ, os.Args[4]); err != nil {
		fmt.Fprintln(os.Stderr, "falha no cálculo das camadas:", err)
		os.Exit(1)
	}
}
