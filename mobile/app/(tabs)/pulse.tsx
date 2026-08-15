import { useMemo, useState } from "react";
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { useRouter } from "expo-router";
import { useQuery } from "@tanstack/react-query";
import { Camera, GeoJSONSource, Layer, Map } from "@maplibre/maplibre-react-native";
import { civicConnectApi } from "../../src/api/client";
import type { HeatmapPoint } from "../../src/api/types";
import { CITY_PULSE_CATEGORIES, CITY_PULSE_MAP_STYLE, CITY_PULSE_STATUSES, cityPulseLabel, heatmapFeatureCollection, normalizeHeatmapPoint } from "../../src/cityPulse";
import { Screen } from "../../src/components/Screen";
import { StatusView } from "../../src/components/StatusView";

function label(value: string): string { return cityPulseLabel(value); }

function PointCard({ point, onPress }: { point: HeatmapPoint; onPress: () => void }) {
  return <Pressable onPress={onPress} style={styles.point} accessibilityRole="button"><View style={styles.pointHeader}><Text style={styles.category}>{label(point.category)}</Text><Text style={[styles.band, point.band === "red" ? styles.high : point.band === "yellow" ? styles.medium : styles.low]}>{point.band} density</Text></View><Text style={styles.pointTitle}>{point.title}</Text><Text style={styles.address}>{point.address}</Text><Text style={styles.meta}>{point.count} reports · {label(point.status)} · severity {point.severity}/5</Text><Text style={styles.openDetails}>Open issue details →</Text></Pressable>;
}

export default function PulseScreen() {
  const router = useRouter();
  const query = useQuery({ queryKey: ["heatmap"], queryFn: civicConnectApi.getHeatmap, refetchInterval: 5000, refetchIntervalInBackground: false });
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("all");
  const [status, setStatus] = useState("all");

  const allPoints = useMemo(() => (query.data?.data.points ?? []).map(normalizeHeatmapPoint).filter((point): point is HeatmapPoint => point !== null), [query.data]);
  const points = useMemo(() => allPoints.filter((point) => {
    const haystack = `${point.title} ${point.address} ${point.category}`.toLowerCase();
    const matchesSearch = !search.trim() || haystack.includes(search.trim().toLowerCase());
    const matchesCategory = category === "all" || point.category === category;
    const matchesStatus = status === "all" || (status === "open" ? point.status !== "resolved" : point.status === "resolved");
    return matchesSearch && matchesCategory && matchesStatus;
  }), [allPoints, category, search, status]);

  const region = points.length ? { latitude: points[0].lat, longitude: points[0].lng } : { latitude: 13.0827, longitude: 80.2707 };
  const featureCollection = useMemo(() => heatmapFeatureCollection(points), [points]);

  if (query.isLoading) return <Screen><ActivityIndicator size="large" color="#67d5ff" /></Screen>;
  if (query.isError || !query.data) return <Screen><StatusView message={query.error?.message ?? "The backend returned no heatmap data."} onRetry={() => void query.refetch()} /></Screen>;

  function openIssue(issueId: number): void { router.push(`/issue/${issueId}`); }

  return (
    <Screen backgroundColor="#08111b" refreshing={query.isRefetching} onRefresh={() => void query.refetch()}>
      <View style={styles.heading}><Text style={styles.eyebrow}>CITY PULSE</Text><Text style={styles.title}>Live issue density</Text><Text style={styles.subtitle}>Explore the same database-backed clusters as the web heatmap. Tap a signal or open a cluster below to see its full workflow.</Text><Pressable onPress={() => router.push("/pulse-map")} style={styles.fullMapButton}><Text style={styles.fullMapButtonText}>Open full-screen draggable map →</Text></Pressable></View>
      <View style={styles.source}><Text style={styles.sourceText}>● DATABASE SIGNAL · {points.length} visible clusters</Text></View>
      <View style={styles.searchWrap}><Text style={styles.searchIcon}>⌕</Text><TextInput value={search} onChangeText={setSearch} placeholder="Search places or issues" placeholderTextColor="#8da3b2" style={styles.search} /></View>
      <View style={styles.filterBlock}><Text style={styles.filterLabel}>Focus the signal</Text><View style={styles.filters}>{CITY_PULSE_STATUSES.map((value) => <Pressable key={value} onPress={() => setStatus(value)} style={[styles.filter, status === value && styles.filterSelected]}><Text style={[styles.filterText, status === value && styles.filterTextSelected]}>{value === "all" ? "All statuses" : value === "open" ? "Open only" : "Resolved"}</Text></Pressable>)}</View><View style={styles.filters}>{CITY_PULSE_CATEGORIES.map((value) => <Pressable key={value} onPress={() => setCategory(value)} style={[styles.filter, category === value && styles.filterSelected]}><Text style={[styles.filterText, category === value && styles.filterTextSelected]}>{value === "all" ? "All categories" : label(value)}</Text></Pressable>)}</View></View>

      <View style={styles.mapShell}>
        <Map style={styles.map} mapStyle={CITY_PULSE_MAP_STYLE} attribution compass scaleBar androidView="texture">
          <Camera initialViewState={{ center: [region.longitude, region.latitude], zoom: points.length ? 10.8 : 9.8 }} />
          <GeoJSONSource id="city-points" data={featureCollection} onPress={(event) => {
            const feature = event.nativeEvent.features?.[0];
            const issueId = Number(feature?.properties?.issueId);
            if (Number.isFinite(issueId) && issueId > 0) openIssue(issueId);
          }}>
            <Layer id="city-heat" type="heatmap" source="city-points" maxzoom={14} paint={{
              "heatmap-weight": ["interpolate", ["linear"], ["get", "weight"], 0, 0.15, 1, 1],
              "heatmap-intensity": 1.15,
              "heatmap-radius": 32,
              "heatmap-opacity": 0.72,
              "heatmap-color": ["interpolate", ["linear"], ["heatmap-density"], 0, "rgba(57,214,162,0)", 0.35, "#39d6a2", 0.65, "#ffbd57", 1, "#ff5264"],
            } as never} />
            <Layer id="city-halos" type="circle" source="city-points" paint={{
              "circle-color": ["match", ["get", "band"], "red", "#ff5264", "yellow", "#ffbd57", "#39d6a2"],
              "circle-radius": ["interpolate", ["linear"], ["get", "count"], 1, 7, 20, 22],
              "circle-opacity": 0.9,
              "circle-stroke-color": "#ecfbff",
              "circle-stroke-width": 1.5,
            } as never} />
          </GeoJSONSource>
        </Map>
        <View pointerEvents="none" style={styles.mapOverlay}><Text style={styles.mapEyebrow}>LIVE COMMUNITY SIGNAL</Text><Text style={styles.mapTitle}>Tap a cluster to inspect it</Text><Text style={styles.mapHint}>Green · low  Yellow · building  Red · high</Text></View>
      </View>

      <View style={styles.clusterHeader}><View><Text style={styles.clusterEyebrow}>SIGNAL EXPLORER</Text><Text style={styles.clusterTitle}>Most active clusters</Text></View><Text style={styles.clusterCount}>{points.length} visible</Text></View>
      {points.length ? points.map((point) => <PointCard key={`${point.id}-${point.lat}-${point.lng}`} point={point} onPress={() => openIssue(point.id)} />) : <Text style={styles.empty}>No clusters match these filters.</Text>}
      <Text style={styles.attribution}>Map tiles © OpenStreetMap contributors © CARTO</Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  heading: { gap: 6 },
  eyebrow: { color: "#67d5ff", fontSize: 11, fontWeight: "900", letterSpacing: 1.1 },
  title: { color: "#edf7fb", fontSize: 27, lineHeight: 32, fontWeight: "900", letterSpacing: -0.6 },
  subtitle: { color: "#b3c5cf", lineHeight: 20 },
  fullMapButton: { alignSelf: "flex-start", paddingVertical: 10, paddingHorizontal: 13, borderRadius: 11, backgroundColor: "#17647f", borderWidth: 1, borderColor: "#67d5ff" },
  fullMapButtonText: { color: "#fff", fontSize: 12, fontWeight: "900" },
  source: { alignSelf: "flex-start", paddingVertical: 7, paddingHorizontal: 10, borderRadius: 999, backgroundColor: "#123328", borderWidth: 1, borderColor: "#285d4d" },
  sourceText: { color: "#7ef0c5", fontSize: 11, fontWeight: "900" },
  searchWrap: { flexDirection: "row", alignItems: "center", minHeight: 48, paddingHorizontal: 12, borderRadius: 12, borderWidth: 1, borderColor: "#2a4657", backgroundColor: "#0d1b28" },
  searchIcon: { color: "#67d5ff", fontSize: 24, marginRight: 7 },
  search: { flex: 1, color: "#edf7fb", paddingVertical: 10 },
  filterBlock: { padding: 14, borderRadius: 16, backgroundColor: "#0d1b28", borderWidth: 1, borderColor: "#213c4d", gap: 9 },
  filterLabel: { color: "#edf7fb", fontSize: 12, fontWeight: "900" },
  filters: { flexDirection: "row", flexWrap: "wrap", gap: 7 },
  filter: { paddingVertical: 7, paddingHorizontal: 10, borderRadius: 999, borderWidth: 1, borderColor: "#2a4657", backgroundColor: "#122433" },
  filterSelected: { backgroundColor: "#17647f", borderColor: "#67d5ff" },
  filterText: { color: "#b3c5cf", fontSize: 11, fontWeight: "700" },
  filterTextSelected: { color: "#fff", fontWeight: "900" },
  mapShell: { height: 330, overflow: "hidden", borderRadius: 20, borderWidth: 1, borderColor: "#2a4657", backgroundColor: "#101e2a" },
  map: { flex: 1 },
  mapOverlay: { position: "absolute", top: 14, left: 14, right: 14, padding: 12, borderRadius: 12, backgroundColor: "rgba(8,17,27,.78)" },
  mapEyebrow: { color: "#67d5ff", fontSize: 10, fontWeight: "900", letterSpacing: 1 },
  mapTitle: { color: "#edf7fb", fontSize: 16, fontWeight: "900", marginTop: 3 },
  mapHint: { color: "#b3c5cf", fontSize: 11, marginTop: 4 },
  clusterHeader: { flexDirection: "row", alignItems: "flex-end", justifyContent: "space-between", gap: 10 },
  clusterEyebrow: { color: "#67d5ff", fontSize: 10, fontWeight: "900", letterSpacing: 1 },
  clusterTitle: { color: "#edf7fb", fontSize: 19, fontWeight: "900", marginTop: 3 },
  clusterCount: { color: "#8da3b2", fontSize: 12, fontWeight: "800" },
  point: { padding: 16, borderRadius: 18, backgroundColor: "#0d1b28", borderWidth: 1, borderColor: "#213c4d", gap: 7 },
  pointHeader: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 10 },
  category: { color: "#67d5ff", fontSize: 12, fontWeight: "900", textTransform: "uppercase", flex: 1 },
  band: { paddingVertical: 4, paddingHorizontal: 8, borderRadius: 999, overflow: "hidden", fontSize: 11, fontWeight: "800" },
  high: { color: "#ffd5d9", backgroundColor: "#641f2b" },
  medium: { color: "#ffe6b4", backgroundColor: "#63461c" },
  low: { color: "#bdf9df", backgroundColor: "#174a3b" },
  pointTitle: { color: "#edf7fb", fontSize: 16, fontWeight: "800" },
  address: { color: "#b3c5cf" },
  meta: { color: "#8da3b2", fontSize: 12 },
  openDetails: { color: "#67d5ff", fontSize: 12, fontWeight: "900" },
  empty: { padding: 24, borderRadius: 16, color: "#8da3b2", backgroundColor: "#0d1b28", textAlign: "center" },
  attribution: { color: "#8da3b2", fontSize: 10, textAlign: "center" },
});
