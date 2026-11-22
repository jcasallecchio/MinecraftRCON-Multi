/* Generate one cached Bedrock structure sector on demand. */
#include "finders.h"
#include <errno.h>
#include <inttypes.h>
#include <stdio.h>
#include <stdlib.h>

typedef struct { int type; const char *id; const char *name; } Structure;
static const Structure regions[] = {
    {Village,"village","Vila"},{Outpost,"outpost","Posto de saqueadores"},
    {Desert_Pyramid,"desert_pyramid","Templo do deserto"},{Jungle_Temple,"jungle_temple","Templo da selva"},
    {Swamp_Hut,"swamp_hut","Cabana do pântano"},{Igloo,"igloo","Iglu"},
    {Ocean_Ruin,"ocean_ruin","Ruínas oceânicas"},{Shipwreck,"shipwreck","Naufrágio"},
    {Treasure,"treasure","Tesouro"},{Monument,"monument","Monumento"},
    {Mansion,"mansion","Mansão"},{Ruined_Portal,"ruined_portal","Portal em ruínas"},
    {Ancient_City,"ancient_city","Cidade ancestral"},{Trail_Ruins,"trail_ruins","Ruínas de trilha"},
    {Trial_Chambers,"trial_chambers","Câmara do desafio"},{Abandoned_Camp,"abandoned_camp","Acampamento"}
};
static const Structure chunks[] = {
    {Mineshaft,"mineshaft","Mina abandonada"},{Ravine,"ravine","Ravina"},
    {Geode,"geode","Geodo"},{Desert_Well,"desert_well","Poço do deserto"},{Lava_Lake,"lava_lake","Lago de lava"}
};
static int floor_div(int a,int b){return a>=0?a/b:-((-a+b-1)/b);}
static void emit(FILE *f,int *first,const char *type,const char *name,int x,int z){
    fprintf(f,"%s{\"type\":\"%s\",\"name\":\"%s\",\"x\":%d,\"z\":%d}",*first?"":",",type,name,x,z);*first=0;
}
int main(int argc,char **argv){
    if(argc!=6){fprintf(stderr,"Usage: %s SEED RADIUS TILE_X TILE_Z OUTPUT_JSON\n",argv[0]);return 2;}
    char *end=NULL;errno=0;int64_t seed=strtoll(argv[1],&end,10);if(errno||!end||*end)return 2;
    long radius=strtol(argv[2],&end,10);if(errno||!end||*end||radius<512||radius>32768)return 2;
    long tx=strtol(argv[3],&end,10);if(errno||!end||*end||tx< -16||tx>15)return 2;
    long tz=strtol(argv[4],&end,10);if(errno||!end||*end||tz< -16||tz>15)return 2;
    const int minx=(int)(tx*2048),minz=(int)(tz*2048),maxx=minx+2048,maxz=minz+2048,mc=MC_26_60;
    Generator g;setupGenerator(&g,mc,0);applySeed(&g,DIM_OVERWORLD,(uint64_t)seed);
    FILE *f=fopen(argv[5],"wb");if(!f){perror("open output");return 1;}
    fprintf(f,"[");int first=1;Pos spawn=getSpawn(&g);
    if(spawn.x>=minx&&spawn.x<maxx&&spawn.z>=minz&&spawn.z<maxz)emit(f,&first,"spawn","Spawn Point",spawn.x,spawn.z);
    for(size_t i=0;i<sizeof(regions)/sizeof(regions[0]);i++){
        StructureConfig c;if(!getStructureConfig(regions[i].type,mc,&c)||c.regionSize<=0||c.dim!=DIM_OVERWORLD)continue;
        int span=c.regionSize*16;
        int rx0=floor_div(minx,span)-1,rx1=floor_div(maxx-1,span)+1;
        int rz0=floor_div(minz,span)-1,rz1=floor_div(maxz-1,span)+1;
        for(int rz=rz0;rz<=rz1;rz++)for(int rx=rx0;rx<=rx1;rx++){
            Pos p;if(!getStructurePos(regions[i].type,mc,(uint64_t)seed,rx,rz,&p))continue;
            if(p.x<minx||p.x>=maxx||p.z<minz||p.z>=maxz||abs(p.x)>=radius||abs(p.z)>=radius)continue;
            if(isViableStructurePos(regions[i].type,&g,p.x,p.z,0))emit(f,&first,regions[i].id,regions[i].name,p.x,p.z);
        }
    }
    int cx0=floor_div(minx,16),cx1=floor_div(maxx-1,16),cz0=floor_div(minz,16),cz1=floor_div(maxz-1,16);
    for(size_t i=0;i<sizeof(chunks)/sizeof(chunks[0]);i++)for(int cz=cz0;cz<=cz1;cz++)for(int cx=cx0;cx<=cx1;cx++){
        Pos p;if(!getStructurePos(chunks[i].type,mc,(uint64_t)seed,cx,cz,&p))continue;
        if(p.x<minx||p.x>=maxx||p.z<minz||p.z>=maxz||abs(p.x)>=radius||abs(p.z)>=radius)continue;
        if(isViableStructurePos(chunks[i].type,&g,p.x,p.z,0))emit(f,&first,chunks[i].id,chunks[i].name,p.x,p.z);
    }
    fprintf(f,"]\n");int bad=ferror(f);if(fclose(f))bad=1;return bad?1:0;
}
