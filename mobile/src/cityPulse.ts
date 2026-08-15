import type { StyleSpecification } from "@maplibre/maplibre-react-native";
import type { HeatmapPoint } from "./api/types";

export const CITY_PULSE_CATEGORIES = ["all", "pothole", "garbage", "streetlight", "waterlogging", "road_damage", "encroachment", "graffiti", "open_drain", "fallen_tree", "other"];
export const CITY_PULSE_STATUSES = ["all", "open", "resolved"];

export const CITY_PULSE_MAP_STYLE: StyleSpecification = {
  version: 8,
  sources: {
    carto: {
      type: "raster",
      tiles: [
        "https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png",
        "https://b.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png",
        "https://c.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png",
      ],
      tileSize: 256,
      attribution: "© OpenStreetMap contributors © CARTO",
    },
  },
  layers: [{ id: "carto", type: "raster", source: "carto" }],
};

export function cityPulseLabel(value: string): string {
  return value.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function normalizeHeatmapPoint(point: HeatmapPoint): HeatmapPoint | null {
  const lat = Number(point.lat);
  const lng = Number(point.lng);
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
  return {
    ...point,
    lat,
    lng,
    count: Math.max(1, Number(point.count) || 1),
    weight: Math.max(0.05, Number(point.weight) || 0.05),
  };
}

export function heatmapFeatureCollection(points: HeatmapPoint[]) {
  return {
    type: "FeatureCollection" as const,
    features: points.map((point) => ({
      type: "Feature" as const,
      id: point.id,
      geometry: { type: "Point" as const, coordinates: [point.lng, point.lat] as [number, number] },
      properties: { issueId: point.id, count: point.count, weight: point.weight, band: point.band },
    })),
  };
}
