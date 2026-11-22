/* Scan Bedrock monster rooms in a bounded area with cubiomes-bedrock. */
#include "finders.h"
#include <errno.h>
#include <inttypes.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>

static int parse_int(const char *value, int *result) {
    char *end = NULL;
    errno = 0;
    long parsed = strtol(value, &end, 10);
    if (errno || !end || *end || parsed < INT_MIN || parsed > INT_MAX) return 0;
    *result = (int)parsed;
    return 1;
}

static int floor_div(int value, int divisor) {
    return value >= 0 ? value / divisor : -((-value + divisor - 1) / divisor);
}

int main(int argc, char **argv) {
    if (argc != 6) {
        fprintf(stderr, "Usage: %s SEED CENTER_X CENTER_Z RADIUS OUTPUT_JSON\n", argv[0]);
        return 2;
    }
    char *end = NULL;
    errno = 0;
    int64_t signed_seed = strtoll(argv[1], &end, 10);
    if (errno || !end || *end) return 2;
    int center_x, center_z, radius;
    if (!parse_int(argv[2], &center_x) || !parse_int(argv[3], &center_z) ||
        !parse_int(argv[4], &radius) || radius < 16 || radius > 32768 || radius % 16 ||
        center_x < -30000000 + radius || center_x > 30000000 - radius ||
        center_z < -30000000 + radius || center_z > 30000000 - radius) return 2;
    FILE *out = fopen(argv[5], "wb");
    if (!out) { perror("Cannot write dungeons"); return 1; }
    const uint64_t seed = (uint64_t)signed_seed;
    Generator generator;
    setupGenerator(&generator, MC_26_60, 0);
    applySeed(&generator, DIM_OVERWORLD, seed);
    TerrainNoise terrain;
    TerrainShaper shaper;
    CaveNoise caves;
    initTerrainNoise(&terrain, seed);
    initTerrainShaper(&shaper);
    initCaveNoise(&caves, seed);
    const char *type[] = {"dungeon_zombie", "dungeon_skeleton", "dungeon_spider"};
    const char *name[] = {"Masmorra · zumbi", "Masmorra · esqueleto", "Masmorra · aranha"};
    int count = 0;
    fprintf(out, "{\"schema_version\":1,\"seed\":\"%" PRId64 "\",\"coverage\":{\"center_x\":%d,\"center_z\":%d,\"radius\":%d},\"points\":[", signed_seed, center_x, center_z, radius);
    for (int cz = floor_div(center_z - radius, 16); cz <= floor_div(center_z + radius - 1, 16); cz++) {
        for (int cx = floor_div(center_x - radius, 16); cx <= floor_div(center_x + radius - 1, 16); cx++) {
            DungeonData rooms[DUNGEON_MAX_PER_CHUNK];
            int found = getDungeons(&generator.bn, &terrain, &shaper, &caves,
                                    MC_26_60, seed, cx, cz, rooms, DUNGEON_MAX_PER_CHUNK);
            for (int i = 0; i < found; i++) {
                DungeonData *room = &rooms[i];
                if (room->pos.x < center_x - radius || room->pos.x >= center_x + radius ||
                    room->pos.z < center_z - radius || room->pos.z >= center_z + radius ||
                    room->mobType < DUNGEON_ZOMBIE || room->mobType > DUNGEON_SPIDER) continue;
                int mob = room->mobType;
                fprintf(out, "%s{\"type\":\"%s\",\"name\":\"%s\",\"x\":%d,\"y\":%d,\"z\":%d}",
                        count ? "," : "", type[mob], name[mob], room->pos.x, room->pos.y, room->pos.z);
                count++;
            }
        }
    }
    fprintf(out, "]}\n");
    int failed = ferror(out);
    if (fclose(out)) failed = 1;
    if (failed) return 1;
    fprintf(stderr, "Scanned %d dungeons within %d blocks of %d,%d.\n", count, radius, center_x, center_z);
    return 0;
}
