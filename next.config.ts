import type { NextConfig } from "next";

const backendOrigin = process.env.LARAVEL_BACKEND_URL
  || (process.env.NODE_ENV === "production" ? "" : "http://localhost:8000");

if (!backendOrigin) {
  throw new Error("LARAVEL_BACKEND_URL must be configured for production builds.");
}

const nextConfig: NextConfig = {
  reactStrictMode: true,
  async rewrites() {
    const origin = backendOrigin.replace(/\/+$/, "");

    return [
      { source: "/api/:path*", destination: `${origin}/api/:path*` },
      { source: "/sanctum/:path*", destination: `${origin}/sanctum/:path*` }
    ];
  }
};

export default nextConfig;
