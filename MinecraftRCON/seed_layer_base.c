/* Bedrock seed base layer generated inside the Home Assistant add-on. */
#include "finders.h"
#include "util.h"
#include <errno.h>
#include <inttypes.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct { int type; const char *id; const char *label; } Structure;
static const Structure structures[] = {
    {Village, "village", "Vila"},
    {Outpost, "outpost", "Posto de saqueadores"},
    {Desert_Pyramid, "desert_pyramid", "Pirâmide do deserto"},
    {Jungle_Temple, "jungle_temple", "Templo da selva"},
    {Swamp_Hut, "swamp_hut", "Cabana do pântano"},
    {Igloo, "igloo", "Iglu"},
    {Ocean_Ruin, "ocean_ruin", "Ruínas oceânicas"},
    {Shipwreck, "shipwreck", "Naufrágio"},
    {Treasure, "treasure", "Tesouro enterrado"},
    {Monument, "monument", "Monumento oceânico"},
    {Mansion, "mansion", "Mansão da floresta"},
    {Ruined_Portal, "ruined_portal", "Portal em ruínas"},
    {Ancient_City, "ancient_city", "Cidade ancestral"},
    {Trail_Ruins, "trail_ruins", "Ruínas de trilha"},
    {Trial_Chambers, "trial_chambers", "Câmaras do desafio"},
    {Abandoned_Camp, "abandoned_camp", "Acampamento abandonado"}
};
static const Structure chunk_features[] = {
    {Mineshaft, "mineshaft", "Mina abandonada"},
    {Ravine, "ravine", "Ravina"},
    {Geode, "geode", "Geodo"},
    {Desert_Well, "desert_well", "Poço do deserto"},
    {Lava_Lake, "lava_lake", "Lago de lava"}
};

int main(int argc, char **argv) {
    if (argc != 7 || strcmp(argv[6], "base-only")) {
        fprintf(stderr, "Usage: %s SEED RADIUS OUTPUT_PPM OUTPUT_JSON OUTPUT_SLIME_PGM base-only\n", argv[0]);
        return 2;
    }
    errno = 0;
    char *end = NULL;
    int64_t signed_seed = strtoll(argv[1], &end, 10);
    if (errno || !end || *end) { fprintf(stderr, "Invalid 64-bit seed.\n"); return 2; }
    errno = 0;
    long requested = strtol(argv[2], &end, 10);
    if (errno || !end || *end || requested < 512 || requested > 32768 || requested % 16) {
        fprintf(stderr, "Radius must be a multiple of 16 between 512 and 32768.\n");
        return 2;
    }
    const int radius = (int)requested;
    const int mc = MC_26_60;
    const int width = radius / 8;
    const int min_block = -radius;
    Generator g;
    setupGenerator(&g, mc, 0);
    applySeed(&g, DIM_OVERWORLD, (uint64_t)signed_seed);
    Range range = { .scale = 16, .x = -radius / 16, .z = -radius / 16,
                    .sx = width, .sz = width, .y = 16, .sy = 1 };
    int *ids = allocCache(&g, range);
    unsigned char *rgb = malloc((size_t)width * width * 3);
    if (!ids || !rgb || genBiomes(&g, ids, range)) {
        fprintf(stderr, "Biome generation failed.\n"); free(ids); free(rgb); return 1;
    }
    unsigned char colors[256][3];
    initBiomeColors(colors);
    if (biomesToImage(rgb, colors, ids, width, width, 1, 2) ||
        savePPM(argv[3], rgb, width, width)) {
        fprintf(stderr, "Biome image generation failed.\n"); free(ids); free(rgb); return 1;
    }
    FILE *slime = fopen(argv[5], "wb");
    if (!slime) { perror("Cannot write slime image"); free(ids); free(rgb); return 1; }
    fprintf(slime, "P5\n%d %d\n255\n", width, width);
    unsigned long slime_count = 0;
    for (int z = 0; z < width; z++) {
        for (int x = 0; x < width; x++) {
            int present = isSlimeChunk(range.x + x, range.z + z);
            if (present) slime_count++;
            fputc(present ? 255 : 0, slime);
        }
    }
    int slime_failed = ferror(slime);
    if (fclose(slime)) slime_failed = 1;
    if (slime_failed) { fprintf(stderr, "Slime image write failed.\n"); free(ids); free(rgb); return 1; }
    FILE *out = fopen(argv[4], "wb");
    if (!out) { perror("Cannot write JSON"); free(ids); free(rgb); return 1; }
    fprintf(out, "{\"schema_version\":1,\"edition\":\"bedrock\",\"generator_version\":\"26.60\",\"seed\":\"%" PRId64 "\",\"dimension\":\"overworld\",\"y\":64,\"min_x\":%d,\"min_z\":%d,\"step\":16,\"width\":%d,\"height\":%d,\"slime_chunks\":%lu,\"biomes\":[",
            signed_seed, min_block, min_block, width, width, slime_count);
    unsigned long counts[256] = {0};
    for (int i = 0; i < width * width; i++) if (ids[i] >= 0 && ids[i] < 256) counts[ids[i]]++;
    int first = 1;
    for (int id = 0; id < 256; id++) {
        if (!counts[id]) continue;
        const char *name = biome2str(mc, id);
        fprintf(out, "%s{\"id\":%d,\"name\":\"%s\",\"color\":\"#%02x%02x%02x\",\"samples\":%lu}",
                first ? "" : ",", id, name ? name : "unknown", colors[id][0], colors[id][1], colors[id][2], counts[id]);
        first = 0;
    }
    fprintf(out, "],\"types\":[{\"type\":\"spawn\",\"name\":\"Ponto de surgimento\"}");
    for (size_t i = 0; i < sizeof(structures) / sizeof(structures[0]); i++)
        fprintf(out, ",{\"type\":\"%s\",\"name\":\"%s\"}", structures[i].id, structures[i].label);
    for (size_t i = 0; i < sizeof(chunk_features) / sizeof(chunk_features[0]); i++)
        fprintf(out, ",{\"type\":\"%s\",\"name\":\"%s\"}", chunk_features[i].id, chunk_features[i].label);
    fprintf(out, "],\"structures\":[");
    /* Sector locations are calculated on demand by minedev-seed-tile. */
    fprintf(out, "]}\n");
    int failed = ferror(out);
    if (fclose(out)) failed = 1;
    free(ids); free(rgb);
    if (failed) { fprintf(stderr, "JSON write failed.\n"); return 1; }
    return 0;
}
